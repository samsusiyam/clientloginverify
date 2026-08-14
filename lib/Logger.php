<?php
/**
 * Client Login Verify - Logger
 * Writes verification events to mod_clientloginverify_logs.
 */

namespace ClientLoginVerify;

class Logger
{
    /**
     * @param int    $clientId
     * @param string $event    e.g. otp_sent, verified, failed, resend
     * @param string $ip
     * @param string $message
     */
    public static function log($clientId, $event, $ip = null, $message = null)
    {
        $logAttempts = \Capsule::table('tbladdonmodules')
            ->where('module', 'clientloginverify')
            ->where('setting', 'logAttempts')
            ->value('value');
        if ($logAttempts !== 'on') {
            return;
        }

        $logIp = \Capsule::table('tbladdonmodules')
            ->where('module', 'clientloginverify')
            ->where('setting', 'logIp')
            ->value('value');

        \Capsule::table('mod_clientloginverify_logs')->insert([
            'client_id'  => $clientId,
            'event'      => $event,
            'ip'         => ($logIp === 'on') ? substr((string) $ip, 0, 45) : null,
            'user_agent' => isset($_SERVER['HTTP_USER_AGENT']) ? substr($_SERVER['HTTP_USER_AGENT'], 0, 500) : null,
            'message'    => $message ? substr($message, 0, 500) : null,
            'created_at' => date('Y-m-d H:i:s'),
        ]);
    }
}
