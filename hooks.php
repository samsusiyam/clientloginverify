<?php
/**
 * Client Login Verify - Hooks
 *
 * ClientLogin      : issue a code and redirect to the verify page.
 * ClientAreaPage   : guard every client area page until the code is entered.
 * ClientLogout     : clear session flags and any pending code.
 * DailyCronJob     : prune old codes and logs.
 *
 * See lib/clv_helper.php for the design rules (no namespace, fully qualified
 * Capsule, PHP 7.2 compatible).
 */

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly.');
}

require_once __DIR__ . '/lib/clv_helper.php';
require_once __DIR__ . '/app/License/LicenseManager.php';

/**
 * After a correct password: check trusted device, create a code, email it,
 * and send the client to the verification page. Fail closed.
 */
add_hook('ClientLogin', 1, function ($vars) {
    try {
        if (!CLV::isEnabled()) {
            return;
        }

        $clientId = 0;
        if (isset($vars['clientID'])) {
            $clientId = (int) $vars['clientID'];
        } elseif (isset($vars['client_id'])) {
            $clientId = (int) $vars['client_id'];
        } elseif (isset($vars['userid'])) {
            $clientId = (int) $vars['userid'];
        }

        $userId = null;
        if (isset($vars['userID'])) {
            $userId = (int) $vars['userID'];
        } elseif (isset($vars['user_id'])) {
            $userId = (int) $vars['user_id'];
        }

        if ($clientId <= 0) {
            return;
        }

        if (!CLV::requires2FA($clientId)) {
            return;
        }

        // Check if this browser/device is already trusted
        if (CLV::isDeviceTrusted($clientId)) {
            CLV::markPassed($clientId);
            return;
        }

        // Capture intended return URL before redirecting to 2FA
        if (!empty($_SESSION['loginurlredirect'])) {
            CLV::sessionSet('clv_return_url', (string) $_SESSION['loginurlredirect']);
        } elseif (!empty($_REQUEST['returnUrl'])) {
            CLV::sessionSet('clv_return_url', (string) $_REQUEST['returnUrl']);
        }

        CLV::sessionSet('clv_passed', false);
        CLV::sessionSet('clv_pending_client', $clientId);
        // Bind the (not yet granted) pass to this specific client so a stale
        // flag from a previous session or account can never satisfy the guard.
        CLV::sessionSet('clv_passed_uid', 0);

        $code = CLV::issueCode($clientId, $userId);

        if (CLV::sendCode($clientId, $code)) {
            CLV::log($clientId, 'otp_sent', 'Verification code emailed');
        } else {
            CLV::log($clientId, 'email_failed', 'Failed to send verification email at login');
            $lang = CLV::loadLang();
            $msg  = isset($lang['email_failed']) ? $lang['email_failed'] : 'We could not send your verification email. Please use "Resend Code" or contact support.';
            CLV::sessionSet('clv_email_error', $msg);
        }

        // Opportunistic cleanup (~1%) so the tables stay tidy even if cron is off.
        if (random_int(1, 100) === 1) {
            CLV::cleanup();
        }
    } catch (\Exception $e) {
        // Fail closed: still force the verification page below.
    }

    CLV::redirect(CLV::verifyUrl());
});

/**
 * Guard: any logged-in client who has not passed 2FA is bounced to the verify
 * page from every client area page except the verify page itself.
 */
add_hook('ClientAreaPage', 1, function ($vars) {
    try {
        if (!CLV::isEnabled()) {
            return;
        }

        $clientId = CLV::currentClientId();
        if ($clientId <= 0) {
            return;
        }
        if (CLV::isVerifyPage()) {
            return;
        }
        if (CLV::passedFor($clientId)) {
            return;
        }
        if (CLV::isDeviceTrusted($clientId)) {
            CLV::markPassed($clientId);
            return;
        }
        if (!CLV::requires2FA($clientId)) {
            return;
        }

        // Save current page URL as return target if not already set
        if (!CLV::sessionGet('clv_return_url') && isset($_SERVER['REQUEST_URI'])) {
            $uri = (string) $_SERVER['REQUEST_URI'];
            if (strpos($uri, 'clvverify=1') === false && strpos($uri, 'logout.php') === false) {
                CLV::sessionSet('clv_return_url', $uri);
            }
        }

        CLV::redirect(CLV::verifyUrl());
    } catch (\Exception $e) {
        // Fail closed unless we are already on the verify page.
        if (!CLV::isVerifyPage()) {
            CLV::redirect(CLV::verifyUrl());
        }
    }
});

/**
 * Clear all 2FA state on logout so the next login starts fresh.
 */
add_hook('ClientLogout', 1, function ($vars) {
    try {
        $clientId = CLV::currentClientId();
        if ($clientId <= 0 && CLV::sessionGet('clv_pending_client')) {
            $clientId = (int) CLV::sessionGet('clv_pending_client');
        }
        if ($clientId > 0) {
            CLV::clearCodes($clientId);
        }
    } catch (\Exception $e) {
        // non fatal
    }

    CLV::sessionForget('clv_passed');
    CLV::sessionForget('clv_passed_uid');
    CLV::sessionForget('clv_passed_sid');
    CLV::sessionForget('clv_pending_client');
    CLV::sessionForget('clv_email_error');
    CLV::sessionForget('clv_return_url');

    if (function_exists('session_regenerate_id')) {
        @session_regenerate_id(true);
    }
});

/**
 * Daily maintenance.
 */
add_hook('DailyCronJob', 1, function ($vars) {
    try {
        CLV::cleanup();
    } catch (\Exception $e) {
        if (function_exists('logActivity')) {
            logActivity('Client Login Verify cleanup failed: ' . $e->getMessage());
        }
    }
});

/**
 * Add 2FA / Login Security item under Client Area Account Menu.
 */
add_hook('ClientAreaSecondaryNavbar', 1, function ($secondaryNavbar) {
    try {
        if (!CLV::isEnabled()) {
            return;
        }
        $accountMenu = $secondaryNavbar->getChild('Account');
        if ($accountMenu) {
            $accountMenu->addChild('ClientLoginVerifySecurity', array(
                'label'   => 'Two-Factor / 2FA Security',
                'uri'     => 'index.php?m=clientloginverify',
                'order'   => 50,
                'icon'    => 'fas fa-shield-alt',
            ));
        }
    } catch (\Exception $e) {
        // non fatal
    }
});
