<?php
/**
 * Client Login Verify - OTP handling
 * Generates, stores (hashed) and verifies one-time login codes.
 */

namespace ClientLoginVerify;

class OTP
{
    public static function generate($clientId, $length = 6, $expiryMinutes = 5, $maxAttempts = 5, $userId = null)
    {
        \Capsule::table('mod_clientloginverify_codes')
            ->where('client_id', $clientId)
            ->whereNull('verified_at')
            ->delete();

        $code = self::random($length);
        $hash = password_hash($code, PASSWORD_DEFAULT);

        \Capsule::table('mod_clientloginverify_codes')->insert([
            'user_id'      => $userId ? (int) $userId : null,
            'client_id'    => $clientId,
            'otp_hash'     => $hash,
            'expires_at'   => Time::dbExpires($expiryMinutes),
            'attempts'     => 0,
            'max_attempts' => $maxAttempts,
            'resends'      => 0,
            'ip_address'   => self::ip(),
            'user_agent'   => isset($_SERVER['HTTP_USER_AGENT']) ? substr($_SERVER['HTTP_USER_AGENT'], 0, 500) : null,
            'created_at'   => Time::dbNow(),
        ]);

        return $code;
    }

    public static function verify($clientId, $input)
    {
        $ip = isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : null;
        if ($ip && Security::ipRateLimited($ip)) {
            Logger::log($clientId, 'rate_limited', $ip);
            return ['success' => false, 'message' => 'Too many verification attempts from your network. Please try again later.'];
        }

        $row = \Capsule::table('mod_clientloginverify_codes')
            ->where('client_id', $clientId)
            ->whereNull('verified_at')
            ->orderBy('created_at', 'desc')
            ->first();

        if (!$row) {
            return ['success' => false, 'message' => 'No active verification code found. Please request a new code.'];
        }
        if (Time::isExpired($row->expires_at)) {
            return ['success' => false, 'message' => 'Your verification code has expired. Please request a new one.'];
        }
        if ((int) $row->attempts >= (int) $row->max_attempts) {
            return ['success' => false, 'message' => 'Too many incorrect attempts. Please request a new code.'];
        }

        $updated = \Capsule::table('mod_clientloginverify_codes')
            ->where('id', $row->id)
            ->where('attempts', '<', $row->max_attempts)
            ->increment('attempts');

        if (!$updated) {
            return ['success' => false, 'message' => 'Too many incorrect attempts. Please request a new code.'];
        }

        $row = \Capsule::table('mod_clientloginverify_codes')->where('id', $row->id)->first();

        if (password_verify($input, $row->otp_hash)) {
            \Capsule::table('mod_clientloginverify_codes')->where('id', $row->id)
                ->update(['verified_at' => Time::dbNow()]);
            return ['success' => true];
        }

        return ['success' => false, 'message' => 'Invalid verification code. Please try again.'];
    }

    public static function hasPending($clientId)
    {
        return \Capsule::table('mod_clientloginverify_codes')
            ->where('client_id', $clientId)
            ->whereNull('verified_at')
            ->where('expires_at', '>', Time::dbNow())
            ->exists();
    }

    public static function cleanupOld($codesDays = 30, $logsDays = 90)
    {
        $cutoffCodes = Time::dbFromTimestamp(Time::timestamp() - ($codesDays * 86400));
        $cutoffLogs  = Time::dbFromTimestamp(Time::timestamp() - ($logsDays * 86400));

        \Capsule::table('mod_clientloginverify_codes')
            ->whereNotNull('verified_at')
            ->where('created_at', '<', $cutoffCodes)
            ->delete();

        \Capsule::table('mod_clientloginverify_logs')
            ->where('created_at', '<', $cutoffLogs)
            ->delete();
    }

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