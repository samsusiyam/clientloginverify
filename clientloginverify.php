<?php
/**
 * Client Login Verify - Addon Module
 * Email-based two-factor authentication (2FA) for WHMCS client logins.
 */

require_once __DIR__ . '/lib/Time.php';
require_once __DIR__ . '/lib/OTP.php';
require_once __DIR__ . '/lib/Mailer.php';
require_once __DIR__ . '/lib/Logger.php';
require_once __DIR__ . '/lib/Security.php';
require_once __DIR__ . '/lib/Session.php';

use ClientLoginVerify\OTP;
use ClientLoginVerify\Mailer;
use ClientLoginVerify\Logger;
use ClientLoginVerify\Security;
use ClientLoginVerify\Session;
use ClientLoginVerify\Time;
use Illuminate\Database\Capsule\Manager as Capsule;

function clientloginverify_config()
{
    return [
        'description' => 'Email-based two-factor authentication (2FA) for WHMCS client logins. After a successful password login, a one-time verification code is emailed to the client and all client-area pages stay locked until the code is entered — protecting accounts from password theft and unauthorized access.',
        'author'      => 'Host Nibo',
        'language'    => 'english',
        'version'     => '1.0',
        'fields'      => [
            'enableModule' => [
                'FriendlyName' => 'Enable Module',
                'Type'         => 'yesno',
                'Description'  => 'Enable email 2FA for client logins',
                'Default'      => 'on',
            ],
            'forceVerification' => [
                'FriendlyName' => 'Force Verification',
                'Type'         => 'yesno',
                'Description'  => 'Require 2FA for every client login',
                'Default'      => 'on',
            ],
            'otpLength' => [
                'FriendlyName' => 'OTP Length',
                'Type'         => 'text',
                'Size'         => '5',
                'Default'      => '6',
                'Description'  => 'Number of digits in the OTP',
            ],
            'otpExpiry' => [
                'FriendlyName' => 'OTP Expiry (minutes)',
                'Type'         => 'text',
                'Size'         => '5',
                'Default'      => '5',
                'Description'  => 'How long the OTP remains valid',
            ],
            'maxAttempts' => [
                'FriendlyName' => 'Maximum Attempts',
                'Type'         => 'text',
                'Size'         => '5',
                'Default'      => '5',
                'Description'  => 'Max incorrect entries before lockout',
            ],
            'resendCooldown' => [
                'FriendlyName' => 'Resend Cooldown (seconds)',
                'Type'         => 'text',
                'Size'         => '5',
                'Default'      => '60',
            ],
            'maxResends' => [
                'FriendlyName' => 'Maximum Resends',
                'Type'         => 'text',
                'Size'         => '5',
                'Default'      => '3',
            ],
            'emailTemplate' => [
                'FriendlyName' => 'Email Template',
                'Type'         => 'text',
                'Size'         => '30',
                'Default'      => 'Client Login Verification',
                'Description'  => 'Name of the WHMCS client email template',
            ],
            'logAttempts' => [
                'FriendlyName' => 'Log Attempts',
                'Type'         => 'yesno',
                'Default'      => 'on',
            ],
            'logIp' => [
                'FriendlyName' => 'Log IP Address',
                'Type'         => 'yesno',
                'Default'      => 'on',
            ],
            'excludedGroups' => [
                'FriendlyName' => 'Excluded Client Groups',
                'Type'         => 'text',
                'Size'         => '30',
                'Description'  => 'Comma separated client group IDs to skip 2FA',
            ],
        ],
    ];
}

function clientloginverify_activate()
{
    try {
        // Use WHMCS database functions (always available during activation)
        $tables = [
            'mod_clientloginverify_codes' => "
                CREATE TABLE IF NOT EXISTS `mod_clientloginverify_codes` (
                    `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
                    `user_id` int(10) unsigned DEFAULT NULL,
                    `client_id` int(10) unsigned NOT NULL,
                    `otp_hash` varchar(255) NOT NULL,
                    `expires_at` datetime NOT NULL,
                    `attempts` int(10) unsigned NOT NULL DEFAULT '0',
                    `max_attempts` int(10) unsigned NOT NULL DEFAULT '5',
                    `resends` int(10) unsigned NOT NULL DEFAULT '0',
                    `ip_address` varchar(45) DEFAULT NULL,
                    `user_agent` text DEFAULT NULL,
                    `verified_at` datetime DEFAULT NULL,
                    `created_at` datetime DEFAULT NULL,
                    PRIMARY KEY (`id`),
                    KEY `client_id` (`client_id`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
            ",
            'mod_clientloginverify_logs' => "
                CREATE TABLE IF NOT EXISTS `mod_clientloginverify_logs` (
                    `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
                    `client_id` int(10) unsigned DEFAULT NULL,
                    `event` varchar(50) NOT NULL,
                    `ip` varchar(45) DEFAULT NULL,
                    `user_agent` text DEFAULT NULL,
                    `message` text DEFAULT NULL,
                    `created_at` datetime DEFAULT NULL,
                    PRIMARY KEY (`id`),
                    KEY `client_id` (`client_id`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
            ",
            'mod_clientloginverify_settings' => "
                CREATE TABLE IF NOT EXISTS `mod_clientloginverify_settings` (
                    `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
                    `client_id` int(10) unsigned NOT NULL,
                    `setting` varchar(50) NOT NULL,
                    `value` text DEFAULT NULL,
                    `created_at` datetime DEFAULT NULL,
                    PRIMARY KEY (`id`),
                    UNIQUE KEY `client_setting` (`client_id`, `setting`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
            ",
        ];

        foreach ($tables as $table => $sql) {
            full_query($sql);
        }

        // Create email template if not exists
        $templateName = 'Client Login Verification';
        $result = select_query('tblemailtemplates', 'id', [
            'name' => $templateName,
            'type' => 'client',
        ]);
        if (!$result || mysql_num_rows($result) === 0) {
            $emailBody = "Hello {\$client_name},\r\n\r\n"
                . "A login attempt was made to your account.\r\n\r\n"
                . "Your verification code is:\r\n\r\n"
                . "{\$code}\r\n\r\n"
                . "This code will expire in {\$expiry} minutes.\r\n\r\n"
                . "Login Details:\r\n"
                . "Date/Time: {\$datetime}\r\n"
                . "IP Address: {\$ip}\r\n"
                . "Browser: {\$browser}\r\n"
                . "Operating System: {\$os}\r\n\r\n"
                . "If you did not initiate this login, please change your password immediately.\r\n\r\n"
                . "Regards,\r\n"
                . "{\$company_name}";

            insert_query('tblemailtemplates', [
                'type'       => 'client',
                'name'       => $templateName,
                'subject'    => 'Your Login Verification Code',
                'message'    => $emailBody,
                'disabled'   => 0,
                'custom'     => 1,
                'created_at' => Time::dbNow(),
                'updated_at' => Time::dbNow(),
            ]);
        }

        return [
            'status'      => 'success',
            'description' => 'Client Login Verify activated. Tables and email template created.',
        ];
    } catch (\Exception $e) {
        return [
            'status'      => 'error',
            'description' => 'Activation failed: ' . $e->getMessage(),
        ];
    }
}

function clientloginverify_deactivate()
{
    return [
        'status'      => 'success',
        'description' => 'Client Login Verify deactivated.',
    ];
}

function clientloginverify_admin_permissions()
{
    return [
        'Manage Client Login Verify',
    ];
}

function clientloginverify_asset_url($file)
{
    $base = '';
    try {
        // Use WHMCS database function instead of Capsule for compatibility
        $result = select_query('tblconfiguration', 'value', ['setting' => 'SystemURL']);
        if ($result && $row = mysql_fetch_assoc($result)) {
            $base = rtrim($row['value'], '/');
        }
    } catch (\Exception $e) {
        $base = '';
    }
    if (!$base && isset($_SERVER['HTTP_HOST'])) {
        $proto = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $base = $proto . '://' . $_SERVER['HTTP_HOST'];
    }
    return $base . '/modules/addons/clientloginverify/' . ltrim($file, '/');
}

function clientloginverify_output($vars)
{
    $modulelink = $vars['modulelink'];
    $logoUrl = clientloginverify_asset_url('assets/logo.jpg');
    $view   = isset($_REQUEST['view']) ? $_REQUEST['view'] : '';
    $action = isset($_REQUEST['action']) ? $_REQUEST['action'] : '';

    if ($action === 'setclient' && isset($_REQUEST['client_id'], $_REQUEST['val'])) {
        check_token();
        $cid = (int) $_REQUEST['client_id'];
        $val = ($_REQUEST['val'] === 'on') ? 'on' : 'off';
        $row = \Capsule::table('mod_clientloginverify_settings')
            ->where('client_id', $cid)
            ->where('setting', 'twofa_enabled')
            ->first();
        if ($row) {
            \Capsule::table('mod_clientloginverify_settings')->where('id', $row->id)
                ->update(['value' => $val, 'created_at' => Time::dbNow()]);
        } else {
            \Capsule::table('mod_clientloginverify_settings')->insert([
                'client_id'  => $cid,
                'setting'    => 'twofa_enabled',
                'value'      => $val,
                'created_at' => Time::dbNow(),
            ]);
        }
        echo '<div class="infobox"><strong>Saved.</strong></div>';
    }

    $smartyVars = ['modulelink' => $modulelink, 'view' => $view, 'logo_url' => $logoUrl];

    if ($view === 'logs') {
        $smartyVars['logs'] = \Capsule::table('mod_clientloginverify_logs')
            ->orderBy('created_at', 'desc')
            ->limit(200)
            ->get();
    } elseif ($view === 'clients') {
        $rows = \Capsule::table('tblclients')->orderBy('id', 'asc')->limit(100)->get();
        $overrides = \Capsule::table('mod_clientloginverify_settings')
            ->where('setting', 'twofa_enabled')
            ->pluck('value', 'client_id');
        $clients = [];
        foreach ($rows as $c) {
            $override = $overrides[$c->id] ?? null;
            $effective = Security::requires2FA($c->id) ? 'Required' : 'Skipped';
            $current   = $override ?: 'default';
            $next      = ($current === 'off') ? 'on' : 'off';
            $label     = ($current === 'off') ? 'Enable' : 'Disable';
            $clients[] = (object) [
                'id'        => $c->id,
                'name'      => trim($c->firstname . ' ' . $c->lastname),
                'groupid'   => $c->groupid,
                'effective' => $effective,
                'current'   => $current,
                'next'      => $next,
                'label'     => $label,
            ];
        }
        $smartyVars['clients'] = $clients;
        $smartyVars['token']   = generate_token('link');
    } else {
        $smartyVars['pending']   = \Capsule::table('mod_clientloginverify_codes')
            ->whereNull('verified_at')
            ->where('expires_at', '>', Time::dbNow())
            ->count();
        $smartyVars['totalLogs'] = \Capsule::table('mod_clientloginverify_logs')->count();
    }

    $template = ($view === 'clients' || $view === 'logs') ? 'settings.tpl' : 'admin.tpl';

    echo clientloginverify_render_admin($template, $smartyVars);
}

function clientloginverify_render_admin($template, $vars)
{
    if (class_exists('WHMCS\\View\\Smarty')) {
        try {
            $smarty = new \WHMCS\View\Smarty();
            $smarty->setTemplateDir(__DIR__ . '/templates');
            foreach ($vars as $k => $v) {
                $smarty->assign($k, $v);
            }
            return $smarty->fetch($template);
        } catch (\Exception $e) {
            // fall through
        }
    }
    if (class_exists('WHMCS\\Smarty')) {
        try {
            $smarty = new \WHMCS\Smarty();
            $smarty->setTemplateDir(__DIR__ . '/templates');
            foreach ($vars as $k => $v) {
                $smarty->assign($k, $v);
            }
            return $smarty->fetch($template);
        } catch (\Exception $e) {
            // fall through
        }
    }
    return clientloginverify_admin_fallback($vars);
}

function clientloginverify_admin_fallback($vars)
{
    $modulelink = $vars['modulelink'];
    $view = isset($vars['view']) ? $vars['view'] : '';

    if ($view === 'logs') {
        $html = '<p><img src="' . htmlspecialchars($vars['logo_url']) . '" alt="Client Login Verify" style="max-height:48px;margin-bottom:12px;"></p>'
            . '<h2>Verification Logs</h2><p><a href="' . $modulelink . '">&laquo; Back</a></p>'
            . '<table class="datatable" width="100%" border="0" cellspacing="1" cellpadding="3">'
            . '<thead><tr><th>Client ID</th><th>Event</th><th>IP</th><th>Message</th><th>Date/Time</th></tr></thead><tbody>';
        foreach ($vars['logs'] as $log) {
            $html .= '<tr><td>' . (int) $log->client_id . '</td><td>' . htmlspecialchars($log->event)
                . '</td><td>' . htmlspecialchars((string) $log->ip) . '</td><td>'
                . htmlspecialchars((string) $log->message) . '</td><td>' . htmlspecialchars($log->created_at) . '</td></tr>';
        }
        return $html . '</tbody></table>';
    }

    if ($view === 'clients') {
        $html = '<p><img src="' . htmlspecialchars($vars['logo_url']) . '" alt="Client Login Verify" style="max-height:48px;margin-bottom:12px;"></p>'
            . '<h2>Client 2FA Status</h2><p><a href="' . $modulelink . '">&laquo; Back</a></p>'
            . '<table class="datatable" width="100%" border="0" cellspacing="1" cellpadding="3">'
            . '<thead><tr><th>ID</th><th>Name</th><th>Group</th><th>2FA</th><th>Action</th></tr></thead><tbody>';
        foreach ($vars['clients'] as $c) {
            $html .= '<tr><td>' . (int) $c->id . '</td><td>' . htmlspecialchars($c->name) . '</td><td>'
                . (int) $c->groupid . '</td><td>' . htmlspecialchars($c->effective) . ' (' . htmlspecialchars($c->current) . ')</td><td>'
                . '<a href="' . $modulelink . '&view=clients&action=setclient&client_id=' . (int) $c->id . '&val=' . $c->next
                . '&token=' . urlencode($vars['token']) . '">'
                . htmlspecialchars($c->label) . '</a></td></tr>';
        }
        return $html . '</tbody></table>';
    }

    return '<p><img src="' . htmlspecialchars($vars['logo_url']) . '" alt="Client Login Verify" style="max-height:48px;margin-bottom:12px;"></p>'
        . '<h2>Client Login Verify</h2>'
        . '<p>Email-based two-factor authentication for client logins.</p>'
        . '<ul><li><strong>Pending verifications:</strong> ' . (int) ($vars['pending'] ?? 0) . '</li>'
        . '<li><strong>Total log entries:</strong> ' . (int) ($vars['totalLogs'] ?? 0) . '</li></ul>'
        . '<p><a class="btn btn-default" href="' . $modulelink . '&view=clients">Client 2FA Status</a> '
        . '<a class="btn btn-default" href="' . $modulelink . '&view=logs">View Logs</a></p>';
}

function clientloginverify_lang()
{
    $lang = [];
    $file = __DIR__ . '/lang/english.php';
    if (file_exists($file)) {
        include $file;
    }
    return $lang;
}

function clientloginverify_clientarea($vars)
{
    $lang = clientloginverify_lang();
    $clientId = Session::get('uid');

    $base = [
        'pagetitle'    => $lang['title'],
        'breadcrumb'   => ['index.php?m=clientloginverify&clvverify=1' => $lang['title']],
        'templatefile'  => 'verify',
        'requirelogin'  => true,
        'forcessl'      => false,
        'vars'          => [],
    ];

    if (!$clientId) {
        return $base;
    }

    try {
        if (!isset($_GET['clvverify']) || $_GET['clvverify'] !== '1') {
            $base['vars']['normalview'] = true;
            $base['vars']['lang']       = $lang;
            return $base;
        }

        if (!Security::requires2FA($clientId)) {
            redir('rp=/');
        }

        if (Session::get('clv_2fa_passed') === true) {
            redir('rp=/');
        }

        if (!OTP::hasPending($clientId)) {
            redir('rp=/login', 'clientarea.php');
            exit;
        }

        $otpLength = (int) Security::setting('otpLength', 6);

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            if (!check_token()) {
                exit;
            }
            if (isset($_POST['clv_otp'])) {
                $otp    = preg_replace('/\D/', '', $_POST['clv_otp']);
                $result = OTP::verify($clientId, $otp);
                if ($result['success']) {
                    Session::set('clv_2fa_passed', true);
                    Logger::log($clientId, 'verified', $_SERVER['REMOTE_ADDR'] ?? null);
                    if (function_exists('session_regenerate_id')) {
                        session_regenerate_id(true);
                    }
                    redir('rp=/');
                } else {
                    Logger::log($clientId, 'failed', $_SERVER['REMOTE_ADDR'] ?? null, $result['message']);
                    $base['vars']['error'] = $result['message'];
                }
            } elseif (isset($_GET['action']) && $_GET['action'] === 'resend') {
                $res = Security::resend($clientId);
                if ($res['ok']) {
                    $base['vars']['info'] = $lang['code_resent'];
                } else {
                    $base['vars']['error'] = $res['message'];
                }
            }
        }

        // Display email failure error from ClientLogin hook
        $emailError = Session::get('clv_email_error');
        if ($emailError) {
            Session::delete('clv_email_error');
            $base['vars']['error'] = $emailError;
        }

        $base['vars']['token']      = generate_token('plain');
        $base['vars']['otp_length'] = $otpLength;
        $base['vars']['lang']       = $lang;
    } catch (\Exception $e) {
        $base['vars']['error'] = 'An unexpected error occurred. Please try again or contact support.';
        $base['vars']['token'] = function_exists('generate_token') ? generate_token('plain') : '';
        $base['vars']['lang']  = $lang;
    }

    return $base;
}