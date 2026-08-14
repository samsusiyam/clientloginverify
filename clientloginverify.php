<?php
/**
 * Client Login Verify - Addon Module
 * Email-based two-factor authentication (2FA) for WHMCS client logins.
 */

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

function clientloginverify_config()
{
    return [
        'description' => 'Adds email-based two-factor authentication (2FA) on client login. A one-time PIN is emailed and client area access is blocked until verified.',
        'author'      => 'WHMCSModule Networks',
        'language'    => 'english',
        'version'     => '1.0.0',
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
        if (!\Capsule::schema()->hasTable('mod_clientloginverify_codes')) {
            \Capsule::schema()->create('mod_clientloginverify_codes', function ($table) {
                $table->increments('id');
                $table->integer('user_id')->unsigned()->nullable();
                $table->integer('client_id')->unsigned();
                $table->string('otp_hash', 255);
                $table->dateTime('expires_at');
                $table->integer('attempts')->default(0);
                $table->integer('max_attempts')->default(5);
                $table->integer('resends')->default(0);
                $table->string('ip_address', 45)->nullable();
                $table->text('user_agent')->nullable();
                $table->dateTime('verified_at')->nullable();
                $table->dateTime('created_at')->nullable();
                $table->index('client_id');
            });
        }

        if (!\Capsule::schema()->hasTable('mod_clientloginverify_logs')) {
            \Capsule::schema()->create('mod_clientloginverify_logs', function ($table) {
                $table->increments('id');
                $table->integer('client_id')->unsigned()->nullable();
                $table->string('event', 50);
                $table->string('ip', 45)->nullable();
                $table->text('user_agent')->nullable();
                $table->text('message')->nullable();
                $table->dateTime('created_at')->nullable();
                $table->index('client_id');
            });
        }

        if (!\Capsule::schema()->hasTable('mod_clientloginverify_settings')) {
            \Capsule::schema()->create('mod_clientloginverify_settings', function ($table) {
                $table->increments('id');
                $table->integer('client_id')->unsigned();
                $table->string('setting', 50);
                $table->text('value')->nullable();
                $table->dateTime('created_at')->nullable();
                $table->unique(['client_id', 'setting']);
            });
        }

        // Create the email template if it does not exist
        $templateName = 'Client Login Verification';
        $exists = \Capsule::table('tblemailtemplates')
            ->where('name', $templateName)
            ->where('type', 'client')
            ->exists();

        if (!$exists) {
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

            \Capsule::table('tblemailtemplates')->insert([
                'type'       => 'client',
                'name'       => $templateName,
                'subject'    => 'Your Login Verification Code',
                'message'    => $emailBody,
                'disabled'   => 0,
                'custom'     => 1,
                'created_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s'),
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
    // Data is preserved on deactivation.
    return [
        'status'      => 'success',
        'description' => 'Client Login Verify deactivated.',
    ];
}

function clientloginverify_output($vars)
{
    $modulelink = $vars['modulelink'];
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
                ->update(['value' => $val, 'created_at' => date('Y-m-d H:i:s')]);
        } else {
            \Capsule::table('mod_clientloginverify_settings')->insert([
                'client_id'  => $cid,
                'setting'    => 'twofa_enabled',
                'value'      => $val,
                'created_at' => date('Y-m-d H:i:s'),
            ]);
        }
        echo '<div class="infobox"><strong>Saved.</strong></div>';
    }

    $smartyVars = ['modulelink' => $modulelink, 'view' => $view];

    if ($view === 'logs') {
        $smartyVars['logs'] = \Capsule::table('mod_clientloginverify_logs')
            ->orderBy('created_at', 'desc')
            ->limit(200)
            ->get();
    } elseif ($view === 'clients') {
        $rows = \Capsule::table('tblclients')->orderBy('id', 'asc')->limit(100)->get();
        // Bulk-load overrides once instead of one query per client (N+1 fix).
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
            ->where('expires_at', '>', date('Y-m-d H:i:s'))
            ->count();
        $smartyVars['totalLogs'] = \Capsule::table('mod_clientloginverify_logs')->count();
    }

    $template = ($view === 'clients' || $view === 'logs') ? 'settings.tpl' : 'admin.tpl';

    echo clientloginverify_render_admin($template, $smartyVars);
}

/**
 * Render an admin template via WHMCS\Smarty, falling back to inline HTML
 * if the Smarty engine is unavailable in this context.
 */
function clientloginverify_render_admin($template, $vars)
{
    if (class_exists('WHMCS\\Smarty')) {
        try {
            $smarty = new \WHMCS\Smarty();
            $smarty->setTemplateDir(__DIR__ . '/templates');
            foreach ($vars as $k => $v) {
                $smarty->assign($k, $v);
            }
            return $smarty->fetch($template);
        } catch (\Exception $e) {
            // fall through to inline fallback
        }
    }
    return clientloginverify_admin_fallback($vars);
}

function clientloginverify_admin_fallback($vars)
{
    $modulelink = $vars['modulelink'];
    $view = isset($vars['view']) ? $vars['view'] : '';

    if ($view === 'logs') {
        $html = '<h2>Verification Logs</h2><p><a href="' . $modulelink . '">&laquo; Back</a></p>'
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
        $html = '<h2>Client 2FA Status</h2><p><a href="' . $modulelink . '">&laquo; Back</a></p>'
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

    return '<h2>Client Login Verify</h2>'
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
        return $base; // requirelogin will redirect to login
    }

    // Normal module access (e.g. client-area menu link): show a simple
    // status page only. Never generate or email an OTP here.
    if (!isset($_GET['clvverify']) || $_GET['clvverify'] !== '1') {
        $base['vars']['normalview'] = true;
        $base['vars']['lang']       = $lang;
        return $base;
    }

    // On the verification page, an exempt client has nothing to verify.
    if (!Security::requires2FA($clientId)) {
        redir('rp=/');
    }

    // Already verified this session -> no need to re-issue a code.
    if (Session::get('clv_2fa_passed') === true) {
        redir('rp=/');
    }

    $otpLength = (int) Security::setting('otpLength', 6);

    // Ensure a pending code exists (e.g. direct navigation after expiry)
    if (!isset($_POST['clv_otp']) && !(isset($_GET['action']) && $_GET['action'] === 'resend')) {
        if (!OTP::hasPending($clientId)) {
            $expiry      = (int) Security::setting('otpExpiry', 5);
            $maxAttempts = (int) Security::setting('maxAttempts', 5);
            $code = OTP::generate($clientId, $otpLength, $expiry, $maxAttempts);
            Mailer::sendCode($clientId, $code, $expiry);
            $base['vars']['info'] = $lang['code_resent'];
        }
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['clv_otp'])) {
        check_token();
        $otp = preg_replace('/\D/', '', $_POST['clv_otp']);
        $result = OTP::verify($clientId, $otp);
        if ($result['success']) {
            Session::set('clv_2fa_passed', true);
            Logger::log($clientId, 'verified', $_SERVER['REMOTE_ADDR'] ?? null);
            redir('rp=/');
        } else {
            Logger::log($clientId, 'failed', $_SERVER['REMOTE_ADDR'] ?? null, $result['message']);
            $base['vars']['error'] = $result['message'];
        }
    }

    if (isset($_GET['action']) && $_GET['action'] === 'resend') {
        check_token();
        $res = Security::resend($clientId);
        if ($res['ok']) {
            $base['vars']['info'] = $lang['code_resent'];
        } else {
            $base['vars']['error'] = $res['message'];
        }
    }

    $base['vars']['token']      = generate_token('plain');
    $base['vars']['otp_length'] = $otpLength;
    $base['vars']['lang']       = $lang;

    return $base;
}
