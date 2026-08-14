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

/**
 * After a correct password: create a code, email it, and send the client to
 * the verification page. Fail closed - if anything goes wrong the client is
 * still redirected to the locked verify page rather than into the account.
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
            CLV::sessionSet('clv_email_error', 'We could not send your verification email. Please use "Resend code" or contact support.');
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
        if (!CLV::requires2FA($clientId)) {
            return;
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
    CLV::sessionForget('clv_pending_client');
    CLV::sessionForget('clv_email_error');

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
