<?php
/**
 * Client Login Verify - Hooks
 * Registers ClientLogin (send OTP), ClientAreaPage (guard) and related hooks.
 */

use ClientLoginVerify\OTP;
use ClientLoginVerify\Mailer;
use ClientLoginVerify\Logger;
use ClientLoginVerify\Security;
use ClientLoginVerify\Session;
use Illuminate\Database\Capsule\Manager as Capsule;

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly.');
}

// Guarded loader: never fatal the hooks bootstrap if a lib file is missing.
foreach (['Time', 'OTP', 'Mailer', 'Logger', 'Security', 'Session'] as $clvLib) {
    $clvLibFile = __DIR__ . '/lib/' . $clvLib . '.php';
    if (is_file($clvLibFile)) {
        require_once $clvLibFile;
    }
}
unset($clvLib, $clvLibFile);

if (!function_exists('clv_is_verify_page')) {
    /**
     * The OTP verification page is EXACTLY the module client-area page with
     * m=clientloginverify and clvverify=1. We deliberately do NOT treat a bare
     * ?clvverify=1 on any other client page as the verify page, otherwise the
     * ClientAreaPage guard could be bypassed by appending that param elsewhere.
     */
    function clv_is_verify_page()
    {
        $m  = isset($_GET['m']) ? (string) $_GET['m'] : '';
        $clv = isset($_GET['clvverify']) ? (string) $_GET['clvverify'] : '';
        return ($m === 'clientloginverify' && $clv === '1');
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
    try {
        if (Security::setting('enableModule', 'on') !== 'on') {
            return;
        }
        $clientId = Session::get('uid');
        if ($clientId && Security::requires2FA($clientId) && Session::get('clv_2fa_passed') !== true) {
            redir('m=clientloginverify&clvverify=1', 'clientarea.php');
            exit;
        }
    } catch (\Exception $e) {
        // On any error, fail closed by sending the user to the verification page.
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