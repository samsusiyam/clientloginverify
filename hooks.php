<?php
/**
 * Client Login Verify - Hooks
 * Registers ClientLogin (send OTP), ClientAreaPage (guard) and related hooks.
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

/**
 * Reliable detection of the verify page.
 * WHMCS strips $_GET['m'] before ClientAreaPage fires, so we use a custom
 * parameter (clvverify) which WHMCS never consumes. Falls back to QUERY_STRING.
 */
if (!function_exists('clv_is_verify_page')) {
    function clv_is_verify_page()
    {
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

        // WHMCS ClientLogin hook provides 'clientID' and 'userID' (capitalised).
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

        // New login -> force re-verification
        Session::set('clv_2fa_passed', false);

        $code = OTP::generate($clientId, $length, $expiry, $maxAttempts, $userId);

        // Email failure MUST NOT grant access. Swallow the exception and still
        // redirect to the verification page (fail closed).
        try {
            Mailer::sendCode($clientId, $code, $expiry);
        } catch (\Exception $e) {
            // intentionally ignored: user stays blocked until they can verify
        }
        Logger::log($clientId, 'otp_sent', $_SERVER['REMOTE_ADDR'] ?? null);

        // Occasionally prune old data to keep tables bounded.
        if (random_int(0, 99) === 0) {
            OTP::cleanupOld();
        }
    } catch (\Exception $e) {
        // Any failure -> still force verification (fail closed).
    }

    redir('m=clientloginverify&clvverify=1', 'clientarea.php');
    exit;
});

add_hook('ClientAreaPage', 1, function ($vars) {
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

        // Required but not yet verified (no matter whether a code is pending or
        // expired) -> force the verification page. The verify page re-issues a
        // fresh code when the previous one has expired.
        redir('m=clientloginverify&clvverify=1', 'clientarea.php');
        exit;
    } catch (\Exception $e) {
        // Fail closed: if we cannot confirm verification state, force the
        // verification page rather than rendering protected content.
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
});
