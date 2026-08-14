<?php
/**
 * Client Login Verify - OTP handling
 * Generates, stores (hashed) and verifies one-time login codes.
 */

namespace ClientLoginVerify;

class OTP
{
    /**
     * Generate a new OTP for a client, invalidating any previous pending code.
     *
     * @return string plaintext code (for emailing)
     */
    public static function generate($clientId, $length = 6, $expiryMinutes = 5, $maxAttempts = 5, $userId = null)
    {
        \Capsule::table('mod_clientloginverify_codes')
            ->where('client_id', $clientId)
            ->whereNull('verified_at')
            ->delete();

        $code = self::random($length);
        $hash = password_hash($code, PASSWORD_DEFAULT);
        $now = time();

        \Capsule::table('mod_clientloginverify_codes')->insert([
            'user_id'      => $userId ? (int) $userId : null,
            'client_id'    => $clientId,
            'otp_hash'     => $hash,
            'expires_at'   => date('Y-m-d H:i:s', $now + ($expiryMinutes * 60)),
            'attempts'     => 0,
            'max_attempts' => $maxAttempts,
            'resends'      => 0,
            'ip_address'   => self::ip(),
            'user_agent'   => isset($_SERVER['HTTP_USER_AGENT']) ? substr($_SERVER['HTTP_USER_AGENT'], 0, 500) : null,
            'created_at'   => date('Y-m-d H:i:s', $now),
        ]);

        return $code;
    }

    /**
     * Verify a submitted code for a client.
     *
     * @return array{success:bool,message:string}
     */
    public static function verify($clientId, $input)
    {
        $row = \Capsule::table('mod_clientloginverify_codes')
            ->where('client_id', $clientId)
            ->whereNull('verified_at')
            ->orderBy('created_at', 'desc')
            ->first();

        if (!$row) {
            return ['success' => false, 'message' => 'No active verification code found. Please request a new code.'];
        }
        if (strtotime($row->expires_at) < time()) {
            return ['success' => false, 'message' => 'Your verification code has expired. Please request a new one.'];
        }
        if ((int) $row->attempts >= (int) $row->max_attempts) {
            return ['success' => false, 'message' => 'Too many incorrect attempts. Please request a new code.'];
        }

        \Capsule::table('mod_clientloginverify_codes')->where('id', $row->id)->increment('attempts');

        if (password_verify($input, $row->otp_hash)) {
            \Capsule::table('mod_clientloginverify_codes')->where('id', $row->id)
                ->update(['verified_at' => date('Y-m-d H:i:s')]);
            return ['success' => true];
        }

        return ['success' => false, 'message' => 'Invalid verification code. Please try again.'];
    }

    /**
     * Does the client currently have a valid (non-expired, unverified) pending code?
     */
    public static function hasPending($clientId)
    {
        return \Capsule::table('mod_clientloginverify_codes')
            ->where('client_id', $clientId)
            ->whereNull('verified_at')
            ->where('expires_at', '>', date('Y-m-d H:i:s'))
            ->exists();
    }

    /**
     * Remove old verified codes and old log entries to keep the tables bounded.
     * Intended to be called infrequently (e.g. throttled from the login hook).
     */
    public static function cleanupOld($codesDays = 30, $logsDays = 90)
    {
        \Capsule::table('mod_clientloginverify_codes')
            ->whereNotNull('verified_at')
            ->where('created_at', '<', date('Y-m-d H:i:s', time() - ($codesDays * 86400)))
            ->delete();

        \Capsule::table('mod_clientloginverify_logs')
            ->where('created_at', '<', date('Y-m-d H:i:s', time() - ($logsDays * 86400)))
            ->delete();
    }

    /**
     * Generate a random numeric code of the given length.
     */
    public static function random($length = 6)
    {
        $length = (int) $length;
        if ($length < 4) { $length = 4; }
        if ($length > 8) { $length = 8; }
        $min = (int) pow(10, $length - 1);
        $max = (int) pow(10, $length) - 1;
        return (string) random_int($min, $max);
    }

    protected static function ip()
    {
        return isset($_SERVER['REMOTE_ADDR']) ? substr($_SERVER['REMOTE_ADDR'], 0, 45) : null;
    }
}
