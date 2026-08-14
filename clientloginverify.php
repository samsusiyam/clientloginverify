<?php
/**
 * Client Login Verify
 *
 * Email based two factor authentication (2FA) for WHMCS client logins.
 * After a correct password, a one time code is emailed to the client and every
 * client area page stays locked until the code is entered.
 *
 * @package    ClientLoginVerify
 * @author     Host Nibo
 * @version    3.0
 *
 * Implementation rules (kept intentionally simple, see lib/clv_helper.php):
 *   - No namespace, no `use ... as Capsule` alias anywhere.
 *   - All Eloquent access via fully qualified \WHMCS\Database\Capsule::.
 *   - Activation uses WHMCS native DB functions because Capsule is not loaded
 *     at that stage.
 *   - PHP 7.2 compatible syntax throughout.
 */

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly.');
}

require_once __DIR__ . '/lib/clv_helper.php';

/**
 * Module metadata shown in the WHMCS admin area. Defining `name`, `author` and
 * `description` here (and never fataling before this function is defined) is
 * what prevents the "No description available / Unknown developer" display.
 */
function clientloginverify_config()
{
    $config = array(
        'name'        => 'Client Login Verify',
        'description' => 'Email based two factor authentication (2FA) for WHMCS client logins. After a successful password login a one time verification code is emailed to the client and every client area page stays locked until the code is entered, protecting accounts from password theft.',
        'author'      => 'Host Nibo',
        'language'    => 'english',
        'version'     => '3.0',
        'fields'      => array(),
    );

    foreach (CLV::fields() as $key => $field) {
        $entry = array(
            'FriendlyName' => $field['label'],
            'Type'         => ($field['type'] === 'yesno') ? 'yesno' : 'text',
            'Description'  => isset($field['desc']) ? $field['desc'] : '',
        );
        if ($field['type'] !== 'yesno') {
            $entry['Size']    = '30';
            $entry['Default'] = $field['default'];
        } else {
            $entry['Default'] = $field['default'];
        }
        $config['fields'][$key] = $entry;
    }

    return $config;
}

/**
 * Create the database tables and the email template. Uses WHMCS native DB
 * functions because Capsule is not guaranteed to be available during activate.
 */
function clientloginverify_activate()
{
    try {
        $tables = array(
            "CREATE TABLE IF NOT EXISTS `mod_clientloginverify_codes` (
                `id` INT(10) UNSIGNED NOT NULL AUTO_INCREMENT,
                `user_id` INT(10) UNSIGNED DEFAULT NULL,
                `client_id` INT(10) UNSIGNED NOT NULL,
                `otp_hash` VARCHAR(255) NOT NULL,
                `expires_at` DATETIME NOT NULL,
                `attempts` INT(10) UNSIGNED NOT NULL DEFAULT 0,
                `max_attempts` INT(10) UNSIGNED NOT NULL DEFAULT 5,
                `resends` INT(10) UNSIGNED NOT NULL DEFAULT 0,
                `ip_address` VARCHAR(45) DEFAULT NULL,
                `user_agent` VARCHAR(500) DEFAULT NULL,
                `verified_at` DATETIME DEFAULT NULL,
                `created_at` DATETIME DEFAULT NULL,
                PRIMARY KEY (`id`),
                KEY `client_pending` (`client_id`, `verified_at`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;",

            "CREATE TABLE IF NOT EXISTS `mod_clientloginverify_logs` (
                `id` INT(10) UNSIGNED NOT NULL AUTO_INCREMENT,
                `client_id` INT(10) UNSIGNED DEFAULT NULL,
                `event` VARCHAR(50) NOT NULL,
                `ip` VARCHAR(45) DEFAULT NULL,
                `user_agent` VARCHAR(500) DEFAULT NULL,
                `message` VARCHAR(500) DEFAULT NULL,
                `created_at` DATETIME DEFAULT NULL,
                PRIMARY KEY (`id`),
                KEY `client_id` (`client_id`),
                KEY `ip_time` (`ip`, `created_at`),
                KEY `event_time` (`event`, `created_at`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;",

            "CREATE TABLE IF NOT EXISTS `mod_clientloginverify_settings` (
                `id` INT(10) UNSIGNED NOT NULL AUTO_INCREMENT,
                `client_id` INT(10) UNSIGNED NOT NULL,
                `setting` VARCHAR(50) NOT NULL,
                `value` VARCHAR(255) DEFAULT NULL,
                `created_at` DATETIME DEFAULT NULL,
                PRIMARY KEY (`id`),
                UNIQUE KEY `client_setting` (`client_id`, `setting`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;",
        );

        foreach ($tables as $sql) {
            full_query($sql);
        }

        clientloginverify_create_email_template();

        return array(
            'status'      => 'success',
            'description' => 'Client Login Verify activated. Tables and the email template are ready.',
        );
    } catch (\Exception $e) {
        return array(
            'status'      => 'error',
            'description' => 'Activation failed: ' . $e->getMessage(),
        );
    }
}

/**
 * Deactivation keeps all tables and data (so a mistaken deactivation loses
 * nothing and reactivation restores everything).
 */
function clientloginverify_deactivate()
{
    return array(
        'status'      => 'success',
        'description' => 'Client Login Verify deactivated. Your tables and logs were preserved.',
    );
}

function clientloginverify_admin_permissions()
{
    return array(
        'Manage Settings' => 'Manage Settings',
        'View Logs'       => 'View Logs',
    );
}

/**
 * Insert the client email template if it does not already exist.
 */
function clientloginverify_create_email_template()
{
    $name = 'Client Login Verification';

    $existing = select_query('tblemailtemplates', 'id', array(
        'name' => $name,
        'type' => 'general',
    ));
    if ($existing && function_exists('mysql_num_rows') && mysql_num_rows($existing) > 0) {
        return;
    }

    $message = "<p>Hello {\$client_name},</p>"
        . "<p>A login to your account was requested. Use the verification code below to continue:</p>"
        . "<p style=\"font-size:26px;font-weight:bold;letter-spacing:4px;\">{\$clv_code}</p>"
        . "<p>This code expires in {\$clv_expiry} minutes.</p>"
        . "<p><strong>Request details</strong><br>"
        . "Time: {\$clv_datetime}<br>"
        . "IP address: {\$clv_ip}<br>"
        . "Browser: {\$clv_browser}<br>"
        . "Operating system: {\$clv_os}</p>"
        . "<p>If you did not try to log in, please change your password immediately.</p>"
        . "<p>Regards,<br>{\$company_name}</p>";

    insert_query('tblemailtemplates', array(
        'type'     => 'general',
        'name'     => $name,
        'subject'  => 'Your login verification code',
        'message'  => $message,
        'fromname' => '',
        'fromemail' => '',
        'disabled' => 0,
        'custom'   => 1,
        'language' => '',
        'copyto'   => '',
        'plaintext' => 0,
    ));
}

/* ======================================================================
 * Admin output (addonmodules.php?module=clientloginverify)
 * ==================================================================== */

function clientloginverify_output($vars)
{
    $modulelink = $vars['modulelink'];
    $logo       = CLV::assetUrl('assets/logo.jpg');
    $view       = isset($_REQUEST['view']) ? preg_replace('/[^a-z]/', '', strtolower($_REQUEST['view'])) : 'dashboard';
    $action     = isset($_REQUEST['action']) ? $_REQUEST['action'] : '';
    $notice     = '';
    $noticeType = 'success';

    // ---- Handle POST / GET actions (all CSRF protected) --------------
    if ($action !== '') {
        $tokenOk = (function_exists('check_token')) ? check_token('WHMCS.admin.default') : true;

        if ($action === 'savesettings' && $_SERVER['REQUEST_METHOD'] === 'POST' && $tokenOk) {
            CLV::saveSettings($_POST);
            $notice = 'Settings saved.';
            $view   = 'settings';
        } elseif ($action === 'testemail' && $_SERVER['REQUEST_METHOD'] === 'POST' && $tokenOk) {
            $cid = isset($_POST['test_client']) ? (int) $_POST['test_client'] : 0;
            if ($cid > 0 && CLV::sendCode($cid, CLV::randomCode((int) CLV::setting('otpLength')))) {
                $notice = 'Test email sent to client #' . $cid . '. Check the inbox and the mail log.';
            } else {
                $notice     = 'Test email could not be sent. Check the client ID and your WHMCS mail settings.';
                $noticeType = 'error';
            }
            $view = 'settings';
        } elseif ($action === 'toggleclient' && $tokenOk) {
            $cid = isset($_GET['client_id']) ? (int) $_GET['client_id'] : 0;
            $val = (isset($_GET['val']) && $_GET['val'] === 'on') ? 'on' : 'off';
            if ($cid > 0) {
                CLV::setClientOverride($cid, $val);
                $notice = '2FA ' . ($val === 'on' ? 'enabled' : 'disabled') . ' for client #' . $cid . '.';
            }
            $view = 'clients';
        } elseif ($action === 'clearcode' && $tokenOk) {
            $cid = isset($_GET['client_id']) ? (int) $_GET['client_id'] : 0;
            if ($cid > 0) {
                CLV::clearCodes($cid);
                $notice = 'Pending code cleared for client #' . $cid . '.';
            }
            $view = 'clients';
        }
    }

    echo clientloginverify_render_header($modulelink, $logo, $view, $notice, $noticeType);

    switch ($view) {
        case 'settings':
            echo clientloginverify_view_settings($modulelink);
            break;
        case 'clients':
            echo clientloginverify_view_clients($modulelink);
            break;
        case 'logs':
            echo clientloginverify_view_logs($modulelink);
            break;
        default:
            echo clientloginverify_view_dashboard($modulelink);
            break;
    }
}

function clientloginverify_admin_token()
{
    return function_exists('generate_token') ? generate_token('link') : '';
}

function clientloginverify_render_header($modulelink, $logo, $view, $notice, $noticeType)
{
    $tabs = array(
        'dashboard' => 'Dashboard',
        'settings'  => 'Settings',
        'clients'   => 'Clients',
        'logs'      => 'Logs',
    );

    $html  = '<div style="display:flex;align-items:center;gap:12px;margin-bottom:14px;">';
    $html .= '<img src="' . htmlspecialchars($logo) . '" alt="Client Login Verify" style="max-height:46px;">';
    $html .= '<div><h2 style="margin:0;">Client Login Verify</h2>';
    $html .= '<span style="color:#777;font-size:12px;">Email based 2FA for client logins &middot; by Host Nibo</span></div></div>';

    $status = CLV::isEnabled()
        ? '<span style="background:#e6f4ea;color:#1e7e34;padding:2px 10px;border-radius:12px;font-size:12px;">Active</span>'
        : '<span style="background:#fdecea;color:#b71c1c;padding:2px 10px;border-radius:12px;font-size:12px;">Disabled</span>';
    $html .= '<p style="margin:0 0 12px;">Module status: ' . $status . '</p>';

    if ($notice !== '') {
        $bg = ($noticeType === 'error') ? '#fdecea' : '#e6f4ea';
        $fg = ($noticeType === 'error') ? '#b71c1c' : '#1e7e34';
        $html .= '<div style="background:' . $bg . ';color:' . $fg . ';padding:10px 14px;border-radius:6px;margin-bottom:14px;">'
            . htmlspecialchars($notice) . '</div>';
    }

    $html .= '<ul class="nav nav-tabs" style="margin-bottom:16px;">';
    foreach ($tabs as $key => $label) {
        $active = ($view === $key) ? ' class="active"' : '';
        $style  = ($view === $key) ? 'font-weight:bold;' : '';
        $html  .= '<li' . $active . '><a style="' . $style . '" href="' . htmlspecialchars($modulelink) . '&view=' . $key . '">'
            . $label . '</a></li>';
    }
    $html .= '</ul>';

    return $html;
}

function clientloginverify_view_dashboard($modulelink)
{
    $stats = CLV::stats();

    $card = function ($label, $value, $color) {
        return '<div style="flex:1;min-width:150px;background:#fff;border:1px solid #e3e8ee;border-radius:8px;padding:16px;text-align:center;">'
            . '<div style="font-size:28px;font-weight:700;color:' . $color . ';">' . (int) $value . '</div>'
            . '<div style="color:#777;font-size:13px;margin-top:4px;">' . htmlspecialchars($label) . '</div></div>';
    };

    $html  = '<div style="display:flex;gap:14px;flex-wrap:wrap;margin-bottom:20px;">';
    $html .= $card('Pending verifications', $stats['pending'], '#2f6df6');
    $html .= $card('Verified (24h)', $stats['verified'], '#1e7e34');
    $html .= $card('Failed (24h)', $stats['failed'], '#b71c1c');
    $html .= $card('Total log entries', $stats['totalLogs'], '#5a6b85');
    $html .= '</div>';

    // Recent events
    $html .= '<h3>Recent activity</h3>';
    try {
        $rows = \WHMCS\Database\Capsule::table(CLV::T_LOGS)
            ->orderBy('id', 'desc')
            ->limit(10)
            ->get();
    } catch (\Exception $e) {
        $rows = array();
    }

    if (count($rows) === 0) {
        $html .= '<p style="color:#777;">No activity recorded yet.</p>';
    } else {
        $html .= '<table class="datatable" width="100%" border="0" cellspacing="1" cellpadding="3">';
        $html .= '<thead><tr><th>Client</th><th>Event</th><th>IP</th><th>Message</th><th>Time (UTC)</th></tr></thead><tbody>';
        foreach ($rows as $r) {
            $html .= '<tr><td>' . (int) $r->client_id . '</td><td>' . htmlspecialchars($r->event) . '</td><td>'
                . htmlspecialchars((string) $r->ip) . '</td><td>' . htmlspecialchars((string) $r->message) . '</td><td>'
                . htmlspecialchars((string) $r->created_at) . '</td></tr>';
        }
        $html .= '</tbody></table>';
    }

    $html .= '<p style="margin-top:16px;"><a class="btn btn-default" href="' . htmlspecialchars($modulelink)
        . '&view=logs">View all logs</a></p>';

    return $html;
}

function clientloginverify_view_settings($modulelink)
{
    $token  = clientloginverify_admin_token();
    $fields = CLV::fields();

    $html  = '<form method="post" action="' . htmlspecialchars($modulelink) . '&action=savesettings">';
    $html .= $token;
    $html .= '<table class="form" width="100%" border="0" cellspacing="1" cellpadding="3">';

    foreach ($fields as $key => $field) {
        $current = CLV::setting($key);
        $html .= '<tr><td class="fieldlabel" width="30%">' . htmlspecialchars($field['label']) . '</td><td class="fieldarea">';

        if ($field['type'] === 'yesno') {
            $checked = ($current === 'on') ? ' checked' : '';
            $html   .= '<label><input type="checkbox" name="' . htmlspecialchars($key) . '" value="on"' . $checked . '> Enabled</label>';
        } else {
            $html .= '<input type="text" name="' . htmlspecialchars($key) . '" value="'
                . htmlspecialchars((string) $current) . '" style="min-width:240px;">';
        }

        if (!empty($field['desc'])) {
            $html .= '<br><span style="color:#777;font-size:12px;">' . htmlspecialchars($field['desc']) . '</span>';
        }
        $html .= '</td></tr>';
    }

    $html .= '</table>';
    $html .= '<p><input type="submit" class="btn btn-primary" value="Save Changes"></p>';
    $html .= '</form>';

    // Test email tool
    $html .= '<hr><h3>Send test email</h3>';
    $html .= '<p style="color:#777;font-size:13px;">Send a sample verification email to a client to confirm your WHMCS mail settings work before relying on 2FA.</p>';
    $html .= '<form method="post" action="' . htmlspecialchars($modulelink) . '&action=testemail" style="display:flex;gap:8px;align-items:center;">';
    $html .= $token;
    $html .= '<input type="number" name="test_client" placeholder="Client ID" min="1" style="width:140px;" required>';
    $html .= '<input type="submit" class="btn btn-default" value="Send Test Email">';
    $html .= '</form>';

    return $html;
}

function clientloginverify_view_clients($modulelink)
{
    $token  = clientloginverify_admin_token();
    $search = isset($_GET['q']) ? trim($_GET['q']) : '';
    $page   = isset($_GET['p']) ? max(1, (int) $_GET['p']) : 1;
    $per    = 25;

    try {
        $query = \WHMCS\Database\Capsule::table('tblclients');
        if ($search !== '') {
            $like = '%' . $search . '%';
            $query->where(function ($q) use ($like, $search) {
                $q->where('firstname', 'like', $like)
                  ->orWhere('lastname', 'like', $like)
                  ->orWhere('email', 'like', $like);
                if (ctype_digit($search)) {
                    $q->orWhere('id', (int) $search);
                }
            });
        }
        $total = $query->count();
        $rows  = $query->orderBy('id', 'asc')
            ->skip(($page - 1) * $per)
            ->take($per)
            ->get();
    } catch (\Exception $e) {
        $rows  = array();
        $total = 0;
    }

    $html  = '<form method="get" action="' . htmlspecialchars($_SERVER['PHP_SELF']) . '" style="margin-bottom:12px;">';
    // Preserve WHMCS module routing params.
    $html .= '<input type="hidden" name="module" value="clientloginverify">';
    $html .= '<input type="hidden" name="view" value="clients">';
    $html .= '<input type="text" name="q" value="' . htmlspecialchars($search) . '" placeholder="Search name, email or ID" style="width:260px;">';
    $html .= ' <input type="submit" class="btn btn-default" value="Search">';
    if ($search !== '') {
        $html .= ' <a class="btn btn-default" href="' . htmlspecialchars($modulelink) . '&view=clients">Reset</a>';
    }
    $html .= '</form>';

    if (count($rows) === 0) {
        return $html . '<p style="color:#777;">No clients found.</p>';
    }

    $html .= '<table class="datatable" width="100%" border="0" cellspacing="1" cellpadding="3">';
    $html .= '<thead><tr><th>ID</th><th>Name</th><th>Group</th><th>2FA status</th><th>Actions</th></tr></thead><tbody>';

    foreach ($rows as $c) {
        $override  = CLV::clientOverride($c->id);
        $required  = CLV::requires2FA($c->id);
        $stateText = $required ? 'Required' : 'Not required';
        $overText  = ($override === null) ? 'default' : $override;
        $next      = ($override === 'off') ? 'on' : 'off';
        $label     = ($override === 'off') ? 'Enable 2FA' : 'Disable 2FA';

        $name = trim($c->firstname . ' ' . $c->lastname);
        $html .= '<tr><td>' . (int) $c->id . '</td><td>' . htmlspecialchars($name) . '</td><td>'
            . (int) $c->groupid . '</td><td>' . htmlspecialchars($stateText) . ' <span style="color:#999;">(' . htmlspecialchars($overText) . ')</span></td><td>';
        $html .= '<a class="btn btn-xs btn-default" href="' . htmlspecialchars($modulelink)
            . '&view=clients&action=toggleclient&client_id=' . (int) $c->id . '&val=' . $next
            . '&token=' . urlencode(clientloginverify_token_value()) . '">' . $label . '</a> ';
        $html .= '<a class="btn btn-xs btn-default" href="' . htmlspecialchars($modulelink)
            . '&view=clients&action=clearcode&client_id=' . (int) $c->id
            . '&token=' . urlencode(clientloginverify_token_value()) . '">Clear pending code</a>';
        $html .= '</td></tr>';
    }
    $html .= '</tbody></table>';

    // Pagination
    $pages = (int) ceil($total / $per);
    if ($pages > 1) {
        $html .= '<div style="margin-top:12px;">';
        for ($i = 1; $i <= $pages; $i++) {
            if ($i === $page) {
                $html .= '<strong style="margin:0 4px;">' . $i . '</strong>';
            } else {
                $q = ($search !== '') ? '&q=' . urlencode($search) : '';
                $html .= '<a style="margin:0 4px;" href="' . htmlspecialchars($modulelink) . '&view=clients&p=' . $i . $q . '">' . $i . '</a>';
            }
        }
        $html .= '</div>';
    }

    return $html;
}

/**
 * The link-token value only (used inside anchor hrefs). generate_token('link')
 * returns a full hidden input, so for links we need just the raw value.
 */
function clientloginverify_token_value()
{
    if (!function_exists('generate_token')) {
        return '';
    }
    $field = generate_token('link');
    if (preg_match('/value="([^"]+)"/', $field, $m)) {
        return $m[1];
    }
    return '';
}

function clientloginverify_view_logs($modulelink)
{
    $event  = isset($_GET['event']) ? preg_replace('/[^a-z_]/', '', strtolower($_GET['event'])) : '';
    $client = isset($_GET['client']) ? (int) $_GET['client'] : 0;
    $page   = isset($_GET['p']) ? max(1, (int) $_GET['p']) : 1;
    $per    = 50;

    try {
        $query = \WHMCS\Database\Capsule::table(CLV::T_LOGS);
        if ($event !== '') {
            $query->where('event', $event);
        }
        if ($client > 0) {
            $query->where('client_id', $client);
        }
        $total = $query->count();
        $rows  = $query->orderBy('id', 'desc')
            ->skip(($page - 1) * $per)
            ->take($per)
            ->get();
    } catch (\Exception $e) {
        $rows  = array();
        $total = 0;
    }

    $html  = '<form method="get" action="' . htmlspecialchars($_SERVER['PHP_SELF']) . '" style="margin-bottom:12px;display:flex;gap:8px;flex-wrap:wrap;">';
    $html .= '<input type="hidden" name="module" value="clientloginverify">';
    $html .= '<input type="hidden" name="view" value="logs">';
    $events = array('' => 'All events', 'otp_sent' => 'Code sent', 'verified' => 'Verified', 'failed' => 'Failed', 'resent' => 'Resent', 'email_failed' => 'Email failed', 'throttled' => 'Throttled');
    $html .= '<select name="event">';
    foreach ($events as $val => $label) {
        $sel = ($event === $val) ? ' selected' : '';
        $html .= '<option value="' . htmlspecialchars($val) . '"' . $sel . '>' . htmlspecialchars($label) . '</option>';
    }
    $html .= '</select>';
    $html .= '<input type="number" name="client" value="' . ($client > 0 ? $client : '') . '" placeholder="Client ID" min="1" style="width:130px;">';
    $html .= '<input type="submit" class="btn btn-default" value="Filter">';
    $html .= '<a class="btn btn-default" href="' . htmlspecialchars($modulelink) . '&view=logs">Reset</a>';
    $html .= '</form>';

    if (count($rows) === 0) {
        return $html . '<p style="color:#777;">No log entries match your filter.</p>';
    }

    $html .= '<table class="datatable" width="100%" border="0" cellspacing="1" cellpadding="3">';
    $html .= '<thead><tr><th>ID</th><th>Client</th><th>Event</th><th>IP</th><th>Message</th><th>Time (UTC)</th></tr></thead><tbody>';
    foreach ($rows as $r) {
        $html .= '<tr><td>' . (int) $r->id . '</td><td>' . (int) $r->client_id . '</td><td>' . htmlspecialchars($r->event)
            . '</td><td>' . htmlspecialchars((string) $r->ip) . '</td><td>' . htmlspecialchars((string) $r->message)
            . '</td><td>' . htmlspecialchars((string) $r->created_at) . '</td></tr>';
    }
    $html .= '</tbody></table>';

    $pages = (int) ceil($total / $per);
    if ($pages > 1) {
        $html .= '<div style="margin-top:12px;">';
        $extra = ($event !== '' ? '&event=' . urlencode($event) : '') . ($client > 0 ? '&client=' . $client : '');
        for ($i = 1; $i <= $pages && $i <= 50; $i++) {
            if ($i === $page) {
                $html .= '<strong style="margin:0 4px;">' . $i . '</strong>';
            } else {
                $html .= '<a style="margin:0 4px;" href="' . htmlspecialchars($modulelink) . '&view=logs&p=' . $i . $extra . '">' . $i . '</a>';
            }
        }
        $html .= '</div>';
    }

    return $html;
}

/* ======================================================================
 * Client area verification page
 * ==================================================================== */

function clientloginverify_clientarea($vars)
{
    $lang = array();
    $langFile = __DIR__ . '/lang/english.php';
    if (is_file($langFile)) {
        include $langFile;
    }

    $base = array(
        'pagetitle'    => isset($lang['title']) ? $lang['title'] : 'Verify Your Login',
        'breadcrumb'   => array('index.php?m=clientloginverify' => isset($lang['title']) ? $lang['title'] : 'Verify Your Login'),
        'templatefile' => 'verify',
        'requirelogin' => true,
        'forcessl'     => false,
        'vars'         => array('lang' => $lang),
    );

    $clientId = CLV::currentClientId();
    if ($clientId <= 0) {
        return $base;
    }

    try {
        // Not on the dedicated verify page: nothing to render here.
        if (!CLV::isVerifyPage()) {
            $base['vars']['normalview'] = true;
            return $base;
        }

        if (!CLV::requires2FA($clientId) || CLV::sessionGet('clv_passed') === true) {
            CLV::redirect('clientarea.php');
        }

        if (!CLV::hasPendingCode($clientId)) {
            // No live code (expired or cleared): send them back to log in again.
            CLV::sessionForget('clv_passed');
            CLV::redirect('logout.php');
        }

        $otpLength = (int) CLV::setting('otpLength');

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $tokenOk = function_exists('check_token') ? check_token('WHMCS.default') : true;
            if (!$tokenOk) {
                $base['vars']['error'] = 'Your session has expired. Please try again.';
            } elseif (isset($_GET['action']) && $_GET['action'] === 'resend') {
                $res = CLV::resendCode($clientId);
                $base['vars'][$res['success'] ? 'info' : 'error'] = $res['message'];
            } elseif (isset($_POST['clv_code'])) {
                $res = CLV::verifyCode($clientId, $_POST['clv_code']);
                if ($res['success']) {
                    CLV::sessionSet('clv_passed', true);
                    if (function_exists('session_regenerate_id')) {
                        @session_regenerate_id(true);
                    }
                    CLV::redirect('clientarea.php');
                }
                $base['vars']['error'] = $res['message'];
            }
        }

        // Surface an email delivery failure recorded during login.
        $emailError = CLV::sessionGet('clv_email_error');
        if ($emailError) {
            CLV::sessionForget('clv_email_error');
            if (empty($base['vars']['error'])) {
                $base['vars']['error'] = $emailError;
            }
        }

        $base['vars']['token']      = function_exists('generate_token') ? generate_token('plain') : '';
        $base['vars']['otp_length'] = $otpLength;
    } catch (\Exception $e) {
        $base['vars']['error'] = 'An unexpected error occurred. Please try again or contact support.';
        $base['vars']['token'] = function_exists('generate_token') ? generate_token('plain') : '';
    }

    return $base;
}
