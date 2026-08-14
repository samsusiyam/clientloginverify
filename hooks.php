<?php
/**
 * Client Login Verify - Hooks
 * Registers ClientLogin (send OTP), ClientAreaPage (guard) and related hooks.
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
use Illuminate\Database\Capsule\Manager as Capsule;

if (!function_exists('clv_is_verify_page')) {
    function clv_is_verify_page()
    {
        if (class_exists('WHMCS\\Session') && \WHMCS\Session::get('clv_on_verify_page') === true) {
            return true;
        }
        if (isset($_SESSION['clv_on_verify_page'])) {
            return true;
        }
        if (isset($_GET['clvverify'])) {
            return true;
        }
        $q = [];
        parse_str((string) ($_SERVER['QUERY_STRING'] ?? ''), $q);
        return (($q['clvverify'] ?? '') === '1');
    }
}

add_hook('ClientLogin', 1, function ($vars) {
    try {
        if (Security::setting('enableModule', 'on') !== 'on') {
            return;
        }

        $clientId = isset($vars['clientID']) ? (int) $vars['clientID']
            : (isset($vars['client_id']) ? (int) $vars['client_id'] : 0);
        $userId = isset($vars['userID']) ? (int) $vars['userID']
            : (isset($vars['user_id']) ? (int) $vars['user_id'] : null);
        if (!$clientId) {
            return;
        }

        if (!Security::requires2FA($clientId)) {
            return;
        }

        $length      = (int) Security::setting('otpLength', 6);
        $expiry      = (int) Security::setting('otpExpiry', 5);
        $maxAttempts = (int) Security::setting('maxAttempts', 5);

        Session::set('clv_2fa_passed', false);
        Session::set('clv_pending_client', $clientId);

        $code = OTP::generate($clientId, $length, $expiry, $maxAttempts, $userId);

        $emailSent = false;
        try {
            $emailSent = Mailer::sendCode($clientId, $code, $expiry);
        } catch (\Exception $e) {
            $emailSent = false;
        }

        if (!$emailSent) {
            Logger::log($clientId, 'email_failed', $_SERVER['REMOTE_ADDR'] ?? null, 'Failed to send OTP email');
            Session::set('clv_email_error', 'Failed to send verification email. Please contact support or try again.');
        } else {
            Logger::log($clientId, 'otp_sent', $_SERVER['REMOTE_ADDR'] ?? null);
        }

        if (random_int(0, 99) === 0) {
            OTP::cleanupOld();
        }
    } catch (\Exception $e) {
        // Fail closed.
    }

    redir('m=clientloginverify&clvverify=1', 'clientarea.php');
    exit;
});

add_hook('ClientAreaPageLogin', 100, function ($vars) {
    if (Security::setting('enableModule', 'on') !== 'on') {
        return;
    }
    $clientId = Session::get('uid');
    if ($clientId && Security::requires2FA($clientId) && Session::get('clv_2fa_passed') !== true) {
        redir('m=clientloginverify&clvverify=1', 'clientarea.php');
        exit;
    }
});

add_hook('ClientAreaPage', 100, function ($vars) {
    try {
        if (Security::setting('enableModule', 'on') !== 'on') {
            return;
        }

        $clientId = Session::get('uid');
        if (!$clientId) {
            return;
        }
        if (clv_is_verify_page()) {
            Session::delete('clv_on_verify_page');
            return;
        }
        if (Session::get('clv_2fa_passed') === true) {
            return;
        }
        if (!Security::requires2FA($clientId)) {
            return;
        }

        redir('m=clientloginverify&clvverify=1', 'clientarea.php');
        exit;
    } catch (\Exception $e) {
        if (!clv_is_verify_page()) {
            redir('m=clientloginverify&clvverify=1', 'clientarea.php');
            exit;
        }
    }
});

add_hook('ClientAreaHeadOutput', 1, function ($vars) {
    if (!clv_is_verify_page()) {
        return;
    }
    $root = rtrim($vars['WEB_ROOT'] ?? '', '/');
    return '<link rel="stylesheet" href="' . $root . '/modules/addons/clientloginverify/assets/css/clientloginverify.css">';
});

add_hook('ClientAreaFooterOutput', 1, function ($vars) {
    if (!clv_is_verify_page()) {
        return;
    }
    $root = rtrim($vars['WEB_ROOT'] ?? '', '/');
    return '<script src="' . $root . '/modules/addons/clientloginverify/assets/js/clientloginverify.js"></script>';
});

add_hook('ClientLogout', 1, function ($vars) {
    $clientId = isset($vars['clientID']) ? (int) $vars['clientID']
        : (isset($vars['client_id']) ? (int) $vars['client_id'] : 0);
    if ($clientId) {
        \Capsule::table('mod_clientloginverify_codes')
            ->where('client_id', $clientId)
            ->whereNull('verified_at')
            ->delete();
    }
    Session::delete('clv_2fa_passed');
    Session::delete('clv_pending_client');
    Session::delete('clv_on_verify_page');
    Session::delete('clv_email_error');
    if (function_exists('session_regenerate_id')) {
        session_regenerate_id(true);
    }
});

add_hook('DailyCronJob', 1, function () {
    try {
        \ClientLoginVerify\OTP::cleanupOld(30, 90);
    } catch (\Exception $e) {
        logActivity('Client Login Verify cleanup failed: ' . $e->getMessage());
    }
});