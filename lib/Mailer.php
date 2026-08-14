<?php
/**
 * Client Login Verify - Mailer
 * Sends the OTP email through the WHMCS built-in email system.
 */

namespace ClientLoginVerify;

class Mailer
{
    /**
     * Send the OTP email to a client using a WHMCS client email template.
     *
     * @return bool
     */
    public static function sendCode($clientId, $code, $expiryMinutes)
    {
        $client = \Capsule::table('tblclients')->where('id', $clientId)->first();
        if (!$client) {
            return false;
        }

        $ip = isset($_SERVER['REMOTE_ADDR']) ? $_SERVER['REMOTE_ADDR'] : '';
        $ua = isset($_SERVER['HTTP_USER_AGENT']) ? $_SERVER['HTTP_USER_AGENT'] : '';
        $company = \Capsule::table('tblconfiguration')->where('setting', 'CompanyName')->value('value');

        $mergeFields = [
            'code'         => $code,
            'expiry'       => $expiryMinutes,
            'datetime'     => date('d F Y h:i A'),
            'ip'           => $ip,
            'browser'      => self::browser($ua),
            'os'           => self::os($ua),
            'company_name' => $company,
            'client_name'  => trim($client->firstname . ' ' . $client->lastname),
        ];

        return (bool) \sendMessage(self::templateName(), $clientId, $mergeFields);
    }

    protected static function templateName()
    {
        $val = \Capsule::table('tbladdonmodules')
            ->where('module', 'clientloginverify')
            ->where('setting', 'emailTemplate')
            ->value('value');
        return $val ?: 'Client Login Verification';
    }

    protected static function browser($ua)
    {
        $ua = strtolower($ua);
        if (strpos($ua, 'edg') !== false) return 'Edge';
        if (strpos($ua, 'chrome') !== false) return 'Chrome';
        if (strpos($ua, 'firefox') !== false) return 'Firefox';
        if (strpos($ua, 'safari') !== false) return 'Safari';
        if (strpos($ua, 'opera') !== false) return 'Opera';
        return 'Unknown';
    }

    protected static function os($ua)
    {
        $ua = strtolower($ua);
        if (strpos($ua, 'windows') !== false) return 'Windows';
        if (strpos($ua, 'mac os') !== false) return 'macOS';
        if (strpos($ua, 'android') !== false) return 'Android';
        if (strpos($ua, 'iphone') !== false || strpos($ua, 'ipad') !== false) return 'iOS';
        if (strpos($ua, 'linux') !== false) return 'Linux';
        return 'Unknown';
    }
}
