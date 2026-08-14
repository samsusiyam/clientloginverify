<?php
/**
 * Client Login Verify - Security
 * Decides whether 2FA is required, handles resend limits & per-client overrides.
 */

namespace ClientLoginVerify;

class Security
{
    private static $clampRanges = [
        'otpLength'      => [4, 8],
        'otpExpiry'      => [1, 1440],
        'maxAttempts'    => [1, 20],
        'resendCooldown' => [0, 3600],
        'maxResends'     => [0, 20],
    ];

    private static $cache = [];

    public static function setting($key, $default = '')
    {
        if (array_key_exists($key, self::$cache)) {
            return self::$cache[$key];
        }

        $val = \Capsule::table('tbladdonmodules')
            ->where('module', 'clientloginverify')
            ->where('setting', $key)
            ->value('value');

        if ($val === null) {
            $out = $default;
        } elseif (isset(self::$clampRanges[$key])) {
            $n = (int) $val;
            [$min, $max] = self::$clampRanges[$key];
            if ($n < $min) { $n = $min; }
            if ($n > $max) { $n = $max; }
            $out = (string) $n;
        } else {
            $out = $val;
        }

        self::$cache[$key] = $out;
        return $out;
    }

    public static function requires2FA($clientId)
    {
        $force = self::setting('forceVerification', 'on');
        $pc = self::perClientValue($clientId);

        if ($force === 'on') {
            if ($pc === 'off') {
                return false;
            }
            if (self::inExcludedGroup($clientId)) {
                return false;
            }
            return true;
        }

        return ($pc === 'on');
    }

    public static function canResend($clientId)
    {
        $row = \Capsule::table('mod_clientloginverify_codes')
            ->where('client_id', $clientId)
            ->whereNull('verified_at')
            ->orderBy('created_at', 'desc')
            ->first();

        if (!$row) {
            return ['ok' => false, 'message' => 'No active code to resend.'];
        }

        $cooldown = (int) self::setting('resendCooldown', 60);
        $maxResends = (int) self::setting('maxResends', 3);

        if ((int) $row->resends >= $maxResends) {
            return ['ok' => false, 'message' => 'Maximum resend limit reached. Please wait for the code to expire.'];
        }

        $created = \DateTime::createFromFormat('Y-m-d H:i:s', $row->created_at, new \DateTimeZone('UTC'));
        $elapsed = $created ? (Time::timestamp() - $created->getTimestamp()) : 0;
        if ($elapsed < $cooldown) {
            $wait = $cooldown - $elapsed;
            return ['ok' => false, 'message' => 'Please wait ' . $wait . ' seconds before requesting a new code.'];
        }

        return ['ok' => true];
    }

    /**
     * Global per-IP throttle on verification failures to slow network-level
     * OTP brute forcing beyond the per-client attempt limit.
     */
    public static function ipRateLimited($ip)
    {
        $window = 15; // minutes
        $limit  = 50; // max failed verifications per IP per window
        $cutoff = Time::dbFromTimestamp(Time::timestamp() - $window * 60);
        $count  = \Capsule::table('mod_clientloginverify_logs')
            ->where('event', 'failed')
            ->where('ip', $ip)
            ->where('created_at', '>', $cutoff)
            ->count();
        return $count >= $limit;
    }

    public static function resend($clientId)
    {
        $cooldown   = (int) self::setting('resendCooldown', 60);
        $maxResends = (int) self::setting('maxResends', 3);
        $length     = (int) self::setting('otpLength', 6);
        $expiry     = (int) self::setting('otpExpiry', 5);

        $code = OTP::random($length);

        // Atomic: a single UPDATE with all limit/cooldown conditions in the
        // WHERE clause. Two concurrent resend requests cannot both succeed
        // because the first one resets created_at, so the second fails the
        // cooldown check. Uses UTC_TIMESTAMP() to stay consistent with the
        // UTC-stored created_at column regardless of DB/PHP timezone.
        $affected = \Capsule::table('mod_clientloginverify_codes')
            ->where('client_id', $clientId)
            ->whereNull('verified_at')
            ->where('resends', '<', $maxResends)
            ->whereRaw('TIMESTAMPDIFF(SECOND, created_at, UTC_TIMESTAMP()) >= ?', [$cooldown])
            ->update([
                'otp_hash'   => password_hash($code, PASSWORD_DEFAULT),
                'expires_at' => Time::dbExpires($expiry),
                'created_at' => Time::dbNow(),
                'attempts'   => 0,
                'resends'    => \Capsule::raw('resends + 1'),
            ]);

        if (!$affected) {
            $row = \Capsule::table('mod_clientloginverify_codes')
                ->where('client_id', $clientId)
                ->whereNull('verified_at')
                ->orderBy('created_at', 'desc')
                ->first();
            if (!$row) {
                return ['ok' => false, 'message' => 'No active code to resend.'];
            }
            if ((int) $row->resends >= $maxResends) {
                return ['ok' => false, 'message' => 'Maximum resend limit reached. Please wait for the code to expire.'];
            }
            return ['ok' => false, 'message' => 'Please wait a moment before requesting a new code.'];
        }

        $emailSent = false;
        try {
            $emailSent = Mailer::sendCode($clientId, $code, $expiry);
        } catch (\Exception $e) {
            $emailSent = false;
        }

        if (!$emailSent) {
            // Roll back the resend increment so a failed email does not consume
            // a resend attempt.
            \Capsule::table('mod_clientloginverify_codes')
                ->where('client_id', $clientId)
                ->whereNull('verified_at')
                ->where('resends', '>', 0)
                ->orderBy('created_at', 'desc')
                ->decrement('resends');
            return ['ok' => false, 'message' => 'Failed to send verification email. Please try again later.'];
        }

        return ['ok' => true];
    }

    protected static function perClientValue($clientId)
    {
        $row = \Capsule::table('mod_clientloginverify_settings')
            ->where('client_id', $clientId)
            ->where('setting', 'twofa_enabled')
            ->first();
        return $row ? $row->value : null;
    }

    protected static function inExcludedGroup($clientId)
    {
        $groups = self::setting('excludedGroups', '');
        if (!$groups) {
            return false;
        }
        $client = \Capsule::table('tblclients')->where('id', $clientId)->first();
        if (!$client || !$client->groupid) {
            return false;
        }
        $ids = array_map('trim', explode(',', $groups));
        return in_array((string) $client->groupid, $ids, true);
    }
}