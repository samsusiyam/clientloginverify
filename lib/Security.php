<?php
/**
 * Client Login Verify - Security
 * Decides whether 2FA is required, handles resend limits & per-client overrides.
 */

namespace ClientLoginVerify;

class Security
{
    /**
     * Numeric ranges for known settings. Values are clamped so an admin cannot
     * enter data that breaks verification (e.g. maxAttempts=0 locks every code,
     * otpLength<4 makes the form reject the generated code).
     */
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

    /**
     * Whether the given client must complete email 2FA.
     */
    public static function requires2FA($clientId)
    {
        $force = self::setting('forceVerification', 'on');
        $pc = self::perClientValue($clientId);

        if ($force === 'on') {
            if ($pc === 'off') {
                return false; // admin disabled for this client
            }
            if (self::inExcludedGroup($clientId)) {
                return false;
            }
            return true;
        }

        // force off -> only clients explicitly enabled
        return ($pc === 'on');
    }

    /**
     * Can the client request a resend right now?
     *
     * @return array{ok:bool,message:string}
     */
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

        $elapsed = time() - strtotime($row->created_at);
        if ($elapsed < $cooldown) {
            $wait = $cooldown - $elapsed;
            return ['ok' => false, 'message' => 'Please wait ' . $wait . ' seconds before requesting a new code.'];
        }

        return ['ok' => true];
    }

    /**
     * Generate + email a fresh code on the existing pending row (keeps resend counter).
     *
     * @return array{ok:bool,message:string}
     */
    public static function resend($clientId)
    {
        $check = self::canResend($clientId);
        if (!$check['ok']) {
            return $check;
        }

        $row = \Capsule::table('mod_clientloginverify_codes')
            ->where('client_id', $clientId)
            ->whereNull('verified_at')
            ->orderBy('created_at', 'desc')
            ->first();

        $length = (int) self::setting('otpLength', 6);
        $expiry = (int) self::setting('otpExpiry', 5);

        $code = OTP::random($length);
        \Capsule::table('mod_clientloginverify_codes')->where('id', $row->id)->update([
            'otp_hash'   => password_hash($code, PASSWORD_DEFAULT),
            'expires_at' => date('Y-m-d H:i:s', time() + ($expiry * 60)),
            'created_at' => date('Y-m-d H:i:s'),
            'attempts'   => 0,
            'resends'    => (int) $row->resends + 1,
        ]);

        Mailer::sendCode($clientId, $code, $expiry);
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
