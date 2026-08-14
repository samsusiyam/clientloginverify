<?php
/**
 * Client Login Verify - Core helper
 *
 * Design notes (deliberate, please read before refactoring):
 *
 *  - NO namespace and NO `use ... as Capsule` alias anywhere in this module.
 *    A `use Illuminate\Database\Capsule\Manager as Capsule` alias does not
 *    resolve reliably inside WHMCS module functions and was the cause of
 *    fatal "Class \"Capsule\" not found" errors. Every database call here is
 *    fully qualified as \WHMCS\Database\Capsule:: which WHMCS registers
 *    globally on every request.
 *
 *  - PHP 7.2 compatible syntax only (no typed returns, no array destructuring,
 *    no arrow functions) so it runs unchanged on older and newer stacks.
 *
 *  - All datetimes are stored and compared in UTC to avoid PHP/MySQL timezone
 *    drift silently expiring or extending codes.
 *
 * @package ClientLoginVerify
 */

if (!defined('WHMCS')) {
    die('This file cannot be accessed directly.');
}

if (!class_exists('CLV')) {

class CLV
{
    /** Module name as stored in tbladdonmodules.module */
    const MODULE = 'clientloginverify';

    /** Table names (kept from v1/v2 so existing data is preserved) */
    const T_CODES    = 'mod_clientloginverify_codes';
    const T_LOGS     = 'mod_clientloginverify_logs';
    const T_SETTINGS = 'mod_clientloginverify_settings';

    /** Per-IP failure throttle */
    const IP_WINDOW_MINUTES = 15;
    const IP_MAX_FAILURES   = 50;

    /** Retention for the daily cleanup */
    const KEEP_CODES_DAYS = 30;
    const KEEP_LOGS_DAYS  = 90;

    /** In-request settings cache */
    protected static $cache = array();

    /* ------------------------------------------------------------------
     * Settings
     * ------------------------------------------------------------------ */

    /**
     * Definition of every module setting: label, type, default, description
     * and (for numeric settings) the min/max clamp. The clamp means a bad
     * value in the database can never break the module at runtime.
     */
    public static function fields()
    {
        return array(
            'enableModule' => array(
                'label'   => 'Enable Module',
                'type'    => 'yesno',
                'default' => 'on',
                'desc'    => 'Master switch. Turn off to disable 2FA for every client immediately.',
            ),
            'forceVerification' => array(
                'label'   => 'Force Verification',
                'type'    => 'yesno',
                'default' => 'on',
                'desc'    => 'Require 2FA for all clients. When off, only clients explicitly enabled on the Clients tab are asked for a code.',
            ),
            'otpLength' => array(
                'label'   => 'Code Length',
                'type'    => 'text',
                'default' => '6',
                'desc'    => 'Number of digits in the verification code (4-8).',
                'min'     => 4,
                'max'     => 8,
            ),
            'otpExpiry' => array(
                'label'   => 'Code Expiry (minutes)',
                'type'    => 'text',
                'default' => '5',
                'desc'    => 'How long a code stays valid (1-60).',
                'min'     => 1,
                'max'     => 60,
            ),
            'maxAttempts' => array(
                'label'   => 'Maximum Attempts',
                'type'    => 'text',
                'default' => '5',
                'desc'    => 'Incorrect entries allowed before the code is locked (1-10).',
                'min'     => 1,
                'max'     => 10,
            ),
            'resendCooldown' => array(
                'label'   => 'Resend Cooldown (seconds)',
                'type'    => 'text',
                'default' => '60',
                'desc'    => 'Minimum wait before a new code can be requested (0-600).',
                'min'     => 0,
                'max'     => 600,
            ),
            'maxResends' => array(
                'label'   => 'Maximum Resends',
                'type'    => 'text',
                'default' => '3',
                'desc'    => 'How many times a client may resend a code (0-10).',
                'min'     => 0,
                'max'     => 10,
            ),
            'emailTemplate' => array(
                'label'   => 'Email Template',
                'type'    => 'text',
                'default' => 'Client Login Verification',
                'desc'    => 'Name of the WHMCS client email template used to deliver the code.',
            ),
            'logAttempts' => array(
                'label'   => 'Log Attempts',
                'type'    => 'yesno',
                'default' => 'on',
                'desc'    => 'Record verification events on the Logs tab.',
            ),
            'logIp' => array(
                'label'   => 'Log IP Address',
                'type'    => 'yesno',
                'default' => 'on',
                'desc'    => 'Store the client IP with each log entry. Required for the per-IP brute force throttle.',
            ),
            'excludedGroups' => array(
                'label'   => 'Excluded Client Groups',
                'type'    => 'text',
                'default' => '',
                'desc'    => 'Comma separated client group IDs that skip 2FA (e.g. 1,3).',
            ),
            'debugMode' => array(
                'label'   => 'Debug Mode',
                'type'    => 'yesno',
                'default' => '',
                'desc'    => 'Log detailed email delivery diagnostics to the Logs tab and the WHMCS Activity Log. Turn on only while troubleshooting.',
            ),
        );
    }

    /**
     * Read a single setting, clamped to its allowed range.
     */
    public static function setting($key, $default = null)
    {
        if (array_key_exists($key, self::$cache)) {
            return self::$cache[$key];
        }

        $fields = self::fields();
        if ($default === null) {
            $default = isset($fields[$key]['default']) ? $fields[$key]['default'] : '';
        }

        $value = $default;
        try {
            $stored = \WHMCS\Database\Capsule::table('tbladdonmodules')
                ->where('module', self::MODULE)
                ->where('setting', $key)
                ->value('value');
            if ($stored !== null) {
                $value = $stored;
            }
        } catch (\Exception $e) {
            $value = $default;
        }

        // Clamp numeric settings so a bad stored value cannot break anything.
        if (isset($fields[$key]['min'], $fields[$key]['max'])) {
            $number = (int) $value;
            if ($number < $fields[$key]['min']) {
                $number = (int) $fields[$key]['min'];
            }
            if ($number > $fields[$key]['max']) {
                $number = (int) $fields[$key]['max'];
            }
            $value = (string) $number;
        }

        self::$cache[$key] = $value;
        return $value;
    }

    public static function isEnabled()
    {
        return self::setting('enableModule') === 'on';
    }

    /**
     * Write settings submitted from the in-page settings form.
     */
    public static function saveSettings($post)
    {
        foreach (self::fields() as $key => $field) {
            if ($field['type'] === 'yesno') {
                $value = (isset($post[$key]) && $post[$key] === 'on') ? 'on' : '';
            } else {
                $value = isset($post[$key]) ? trim((string) $post[$key]) : '';
            }

            if (isset($field['min'], $field['max'])) {
                $number = (int) $value;
                if ($number < $field['min']) {
                    $number = (int) $field['min'];
                }
                if ($number > $field['max']) {
                    $number = (int) $field['max'];
                }
                $value = (string) $number;
            }

            $exists = \WHMCS\Database\Capsule::table('tbladdonmodules')
                ->where('module', self::MODULE)
                ->where('setting', $key)
                ->exists();

            if ($exists) {
                \WHMCS\Database\Capsule::table('tbladdonmodules')
                    ->where('module', self::MODULE)
                    ->where('setting', $key)
                    ->update(array('value' => $value));
            } else {
                \WHMCS\Database\Capsule::table('tbladdonmodules')->insert(array(
                    'module'  => self::MODULE,
                    'setting' => $key,
                    'value'   => $value,
                ));
            }
        }

        self::$cache = array();
    }

    /* ------------------------------------------------------------------
     * Time helpers (everything UTC)
     * ------------------------------------------------------------------ */

    public static function now()
    {
        return new \DateTime('now', new \DateTimeZone('UTC'));
    }

    public static function dbNow()
    {
        $now = self::now();
        return $now->format('Y-m-d H:i:s');
    }

    public static function dbPlusMinutes($minutes)
    {
        $date = self::now();
        $date->modify('+' . (int) $minutes . ' minutes');
        return $date->format('Y-m-d H:i:s');
    }

    public static function dbMinusSeconds($seconds)
    {
        $date = self::now();
        $date->modify('-' . (int) $seconds . ' seconds');
        return $date->format('Y-m-d H:i:s');
    }

    public static function isExpired($datetime)
    {
        $expires = \DateTime::createFromFormat('Y-m-d H:i:s', (string) $datetime, new \DateTimeZone('UTC'));
        if (!$expires) {
            return true; // Unparsable value is treated as expired (fail closed).
        }
        return $expires < self::now();
    }

    public static function displayNow()
    {
        $now = self::now();
        return $now->format('d M Y, H:i') . ' UTC';
    }

    /* ------------------------------------------------------------------
     * Request helpers
     * ------------------------------------------------------------------ */

    public static function ip()
    {
        if (!isset($_SERVER['REMOTE_ADDR'])) {
            return '';
        }
        return substr((string) $_SERVER['REMOTE_ADDR'], 0, 45);
    }

    public static function userAgent()
    {
        if (!isset($_SERVER['HTTP_USER_AGENT'])) {
            return '';
        }
        return substr((string) $_SERVER['HTTP_USER_AGENT'], 0, 500);
    }

    /**
     * Logged-in client id straight from the session. We read $_SESSION rather
     * than WHMCS\Session so there is no dependency on internal class names.
     */
    public static function currentClientId()
    {
        if (isset($_SESSION['uid']) && (int) $_SESSION['uid'] > 0) {
            return (int) $_SESSION['uid'];
        }
        return 0;
    }

    public static function sessionGet($key)
    {
        return isset($_SESSION[$key]) ? $_SESSION[$key] : null;
    }

    public static function sessionSet($key, $value)
    {
        $_SESSION[$key] = $value;
    }

    public static function sessionForget($key)
    {
        if (isset($_SESSION[$key])) {
            unset($_SESSION[$key]);
        }
    }

    /**
     * Mark 2FA as passed for the current session, bound to a specific client id
     * so a leftover flag can never satisfy the guard for a different account.
     */
    public static function markPassed($clientId)
    {
        self::sessionSet('clv_passed', true);
        self::sessionSet('clv_passed_uid', (int) $clientId);
    }

    /**
     * True only if 2FA was passed in this session for exactly this client id.
     */
    public static function passedFor($clientId)
    {
        return (self::sessionGet('clv_passed') === true
            && (int) self::sessionGet('clv_passed_uid') === (int) $clientId);
    }

    /**
     * True only on the dedicated verification page. Both parameters are
     * required: a bare ?clvverify=1 on any other page must NOT be treated as
     * the verify page, otherwise the client area guard could be bypassed by
     * appending that parameter to a normal URL.
     */
    public static function isVerifyPage()
    {
        $module = isset($_GET['m']) ? (string) $_GET['m'] : '';
        $flag   = isset($_GET['clvverify']) ? (string) $_GET['clvverify'] : '';
        return ($module === self::MODULE && $flag === '1');
    }

    public static function verifyUrl()
    {
        // Addon module client-area pages are served by index.php?m=<module>.
        // Return an absolute URL so redirects are never resolved relative to a
        // routed path such as /index.php/... (which would 404).
        return self::systemUrl('index.php?m=' . self::MODULE . '&clvverify=1');
    }

    /**
     * Build an absolute front-end URL from the WHMCS SystemURL. Falls back to a
     * root-relative path so the browser resolves it against the domain root
     * (never against the current routed path).
     */
    public static function systemUrl($path)
    {
        $path = ltrim((string) $path, '/');
        $base = '';

        try {
            $base = (string) \WHMCS\Database\Capsule::table('tblconfiguration')
                ->where('setting', 'SystemURL')
                ->value('value');
        } catch (\Throwable $e) {
            $base = '';
        }

        if ($base === '' && isset($_SERVER['HTTP_HOST'])) {
            $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
            $base   = ($secure ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'];
        }

        if ($base === '') {
            // Last resort: root-relative so it is at least resolved from the
            // domain root rather than the current routed path.
            return '/' . $path;
        }

        return rtrim($base, '/') . '/' . $path;
    }

    /**
     * Plain header redirect. Avoids depending on the signature of the WHMCS
     * redir() helper, which differs between versions. Relative paths are made
     * absolute against the SystemURL first so they cannot 404 when the current
     * request was served through a routed path like /index.php/...
     */
    public static function redirect($url)
    {
        if (strpos($url, 'http://') !== 0 && strpos($url, 'https://') !== 0) {
            $url = self::systemUrl($url);
        }
        if (!headers_sent()) {
            header('Location: ' . $url);
        } else {
            echo '<script>window.location.href=' . json_encode($url) . ';</script>';
        }
        exit;
    }

    /* ------------------------------------------------------------------
     * Who needs 2FA
     * ------------------------------------------------------------------ */

    /**
     * Per-client override value: 'on', 'off' or null when not set.
     */
    public static function clientOverride($clientId)
    {
        try {
            $row = \WHMCS\Database\Capsule::table(self::T_SETTINGS)
                ->where('client_id', (int) $clientId)
                ->where('setting', 'twofa_enabled')
                ->first();
            if ($row && ($row->value === 'on' || $row->value === 'off')) {
                return $row->value;
            }
        } catch (\Exception $e) {
            // fall through
        }
        return null;
    }

    public static function setClientOverride($clientId, $value)
    {
        $value = ($value === 'on') ? 'on' : 'off';

        $existing = \WHMCS\Database\Capsule::table(self::T_SETTINGS)
            ->where('client_id', (int) $clientId)
            ->where('setting', 'twofa_enabled')
            ->first();

        if ($existing) {
            \WHMCS\Database\Capsule::table(self::T_SETTINGS)
                ->where('id', $existing->id)
                ->update(array('value' => $value, 'created_at' => self::dbNow()));
        } else {
            \WHMCS\Database\Capsule::table(self::T_SETTINGS)->insert(array(
                'client_id'  => (int) $clientId,
                'setting'    => 'twofa_enabled',
                'value'      => $value,
                'created_at' => self::dbNow(),
            ));
        }
    }

    protected static function inExcludedGroup($clientId)
    {
        $groups = trim((string) self::setting('excludedGroups'));
        if ($groups === '') {
            return false;
        }

        try {
            $client = \WHMCS\Database\Capsule::table('tblclients')
                ->where('id', (int) $clientId)
                ->first();
        } catch (\Exception $e) {
            return false;
        }

        if (!$client || empty($client->groupid)) {
            return false;
        }

        $ids = array_filter(array_map('trim', explode(',', $groups)), 'strlen');
        return in_array((string) $client->groupid, $ids, true);
    }

    /**
     * Decide whether this client must complete 2FA.
     */
    public static function requires2FA($clientId)
    {
        $clientId = (int) $clientId;
        if ($clientId <= 0) {
            return false;
        }
        if (!self::isEnabled()) {
            return false;
        }

        $override = self::clientOverride($clientId);

        if (self::setting('forceVerification') === 'on') {
            if ($override === 'off') {
                return false;
            }
            if (self::inExcludedGroup($clientId)) {
                return false;
            }
            return true;
        }

        // Opt-in mode: only clients explicitly switched on.
        return ($override === 'on');
    }

    /* ------------------------------------------------------------------
     * Codes
     * ------------------------------------------------------------------ */

    public static function randomCode($length)
    {
        $length = (int) $length;
        if ($length < 4) {
            $length = 4;
        }
        if ($length > 8) {
            $length = 8;
        }

        $digits = '';
        for ($i = 0; $i < $length; $i++) {
            // First digit is 1-9 so the code always renders at full length.
            $digits .= ($i === 0) ? (string) random_int(1, 9) : (string) random_int(0, 9);
        }
        return $digits;
    }

    /**
     * Issue a fresh code, replacing any pending one. Returns the plain code so
     * it can be emailed; only the hash is ever stored.
     */
    public static function issueCode($clientId, $userId = null)
    {
        $clientId = (int) $clientId;
        $length   = (int) self::setting('otpLength');
        $expiry   = (int) self::setting('otpExpiry');
        $attempts = (int) self::setting('maxAttempts');

        \WHMCS\Database\Capsule::table(self::T_CODES)
            ->where('client_id', $clientId)
            ->whereNull('verified_at')
            ->delete();

        $code = self::randomCode($length);

        \WHMCS\Database\Capsule::table(self::T_CODES)->insert(array(
            'user_id'      => $userId ? (int) $userId : null,
            'client_id'    => $clientId,
            'otp_hash'     => password_hash($code, PASSWORD_DEFAULT),
            'expires_at'   => self::dbPlusMinutes($expiry),
            'attempts'     => 0,
            'max_attempts' => $attempts,
            'resends'      => 0,
            'ip_address'   => self::ip(),
            'user_agent'   => self::userAgent(),
            'verified_at'  => null,
            'created_at'   => self::dbNow(),
        ));

        return $code;
    }

    public static function pendingCode($clientId)
    {
        try {
            return \WHMCS\Database\Capsule::table(self::T_CODES)
                ->where('client_id', (int) $clientId)
                ->whereNull('verified_at')
                ->orderBy('id', 'desc')
                ->first();
        } catch (\Exception $e) {
            return null;
        }
    }

    public static function hasPendingCode($clientId)
    {
        $row = self::pendingCode($clientId);
        if (!$row) {
            return false;
        }
        return !self::isExpired($row->expires_at);
    }

    public static function clearCodes($clientId)
    {
        try {
            \WHMCS\Database\Capsule::table(self::T_CODES)
                ->where('client_id', (int) $clientId)
                ->whereNull('verified_at')
                ->delete();
        } catch (\Exception $e) {
            // non fatal
        }
    }

    /**
     * Network-wide throttle so an attacker cannot spread guesses across many
     * accounts from one IP. Complements the per-code attempt limit.
     */
    public static function ipThrottled($ip)
    {
        if ($ip === '' || self::setting('logIp') !== 'on') {
            return false;
        }

        try {
            $cutoff = self::dbMinusSeconds(self::IP_WINDOW_MINUTES * 60);
            $count  = \WHMCS\Database\Capsule::table(self::T_LOGS)
                ->where('event', 'failed')
                ->where('ip', $ip)
                ->where('created_at', '>', $cutoff)
                ->count();
            return ($count >= self::IP_MAX_FAILURES);
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Verify a submitted code.
     *
     * @return array array('success' => bool, 'message' => string)
     */
    public static function verifyCode($clientId, $input)
    {
        $clientId = (int) $clientId;
        $input    = preg_replace('/\D/', '', (string) $input);

        if ($input === '') {
            return self::result(false, 'Please enter the verification code.');
        }

        $ip = self::ip();
        if (self::ipThrottled($ip)) {
            self::log($clientId, 'throttled', 'Per-IP failure limit reached');
            return self::result(false, 'Too many failed attempts from your network. Please try again later.');
        }

        $row = self::pendingCode($clientId);
        if (!$row) {
            return self::result(false, 'No active code found. Please request a new one.');
        }
        if (self::isExpired($row->expires_at)) {
            return self::result(false, 'This code has expired. Please request a new one.');
        }
        if ((int) $row->attempts >= (int) $row->max_attempts) {
            return self::result(false, 'Too many incorrect attempts. Please request a new code.');
        }

        // Atomic: the WHERE clause guarantees two concurrent requests cannot
        // both consume the final allowed attempt.
        $claimed = \WHMCS\Database\Capsule::table(self::T_CODES)
            ->where('id', $row->id)
            ->where('attempts', '<', (int) $row->max_attempts)
            ->whereNull('verified_at')
            ->increment('attempts');

        if (!$claimed) {
            return self::result(false, 'Too many incorrect attempts. Please request a new code.');
        }

        $fresh = \WHMCS\Database\Capsule::table(self::T_CODES)->where('id', $row->id)->first();
        if (!$fresh) {
            return self::result(false, 'No active code found. Please request a new one.');
        }

        if (!password_verify($input, $fresh->otp_hash)) {
            $left = (int) $fresh->max_attempts - (int) $fresh->attempts;
            self::log($clientId, 'failed', 'Incorrect code');
            if ($left > 0) {
                return self::result(false, 'Incorrect code. ' . $left . ' attempt(s) remaining.');
            }
            return self::result(false, 'Incorrect code. Please request a new one.');
        }

        \WHMCS\Database\Capsule::table(self::T_CODES)
            ->where('id', $fresh->id)
            ->update(array('verified_at' => self::dbNow()));

        self::log($clientId, 'verified', 'Login verified');
        return self::result(true, 'Verified.');
    }

    /**
     * Resend the pending code. The cooldown and resend cap are enforced inside
     * a single conditional UPDATE, so concurrent clicks cannot both succeed.
     */
    public static function resendCode($clientId)
    {
        $clientId   = (int) $clientId;
        $cooldown   = (int) self::setting('resendCooldown');
        $maxResends = (int) self::setting('maxResends');
        $length     = (int) self::setting('otpLength');
        $expiry     = (int) self::setting('otpExpiry');

        $row = self::pendingCode($clientId);
        if (!$row) {
            return self::result(false, 'No active code to resend. Please log in again.');
        }
        if ((int) $row->resends >= $maxResends) {
            return self::result(false, 'Resend limit reached. Please wait for the code to expire.');
        }

        $code = self::randomCode($length);

        $affected = \WHMCS\Database\Capsule::table(self::T_CODES)
            ->where('id', $row->id)
            ->whereNull('verified_at')
            ->where('resends', '<', $maxResends)
            ->whereRaw('TIMESTAMPDIFF(SECOND, created_at, UTC_TIMESTAMP()) >= ?', array($cooldown))
            ->update(array(
                'otp_hash'   => password_hash($code, PASSWORD_DEFAULT),
                'expires_at' => self::dbPlusMinutes($expiry),
                'created_at' => self::dbNow(),
                'attempts'   => 0,
                'resends'    => \WHMCS\Database\Capsule::raw('resends + 1'),
            ));

        if (!$affected) {
            return self::result(false, 'Please wait a moment before requesting another code.');
        }

        if (!self::sendCode($clientId, $code)) {
            // Do not let a failed email consume one of the client's resends.
            \WHMCS\Database\Capsule::table(self::T_CODES)
                ->where('id', $row->id)
                ->where('resends', '>', 0)
                ->decrement('resends');
            self::log($clientId, 'email_failed', 'Resend email failed');
            return self::result(false, 'We could not send the email. Please try again or contact support.');
        }

        self::log($clientId, 'resent', 'Code resent');
        return self::result(true, 'A new code has been sent to your email address.');
    }

    protected static function result($success, $message)
    {
        return array('success' => (bool) $success, 'message' => $message);
    }

    /* ------------------------------------------------------------------
     * Email
     * ------------------------------------------------------------------ */

    public static function templateName()
    {
        $name = trim((string) self::setting('emailTemplate'));
        return ($name === '') ? 'Client Login Verification' : $name;
    }

    /**
     * Send the code through the WHMCS native mail system.
     *
     * Uses WHMCS's own email abstraction only (localAPI SendEmail), so whatever
     * mail provider WHMCS is configured with - SMTP, Brevo API module, etc. -
     * is used automatically. The module never talks to a mail provider directly.
     *
     * Two native paths are attempted, both through WHMCS:
     *   1. Stored template by name (messagename).
     *   2. Inline general message (customtype=general + custommessage) as a
     *      fallback, because some WHMCS builds throw
     *      "Class WHMCS\Mail\Entity\Client not found" from factoryByTemplate()
     *      when sending a stored template to a client. The inline path avoids
     *      that entity resolution while still using WHMCS's configured provider.
     *
     * Merge field names are prefixed with clv_ so they cannot collide with
     * WHMCS built-in merge fields such as {$ip} or {$code}.
     */
    public static function sendCode($clientId, $code)
    {
        $clientId = (int) $clientId;
        $expiry   = (int) self::setting('otpExpiry');
        $agent    = self::userAgent();

        $merge = array(
            'clv_code'     => $code,
            'clv_expiry'   => $expiry,
            'clv_datetime' => self::displayNow(),
            'clv_ip'       => self::ip(),
            'clv_browser'  => self::browser($agent),
            'clv_os'       => self::os($agent),
        );

        if (!function_exists('localAPI')) {
            self::log($clientId, 'email_failed', 'WHMCS localAPI() unavailable');
            return false;
        }

        $template = self::templateName();
        self::debug($clientId, 'sendCode start, template="' . $template . '"');

        // ---- Path 1: stored template by name -------------------------------
        try {
            $response = localAPI('SendEmail', array(
                'messagename' => $template,
                'id'          => $clientId,
                'customvars'  => base64_encode(serialize($merge)),
            ));
            self::debug($clientId, 'template send response: ' . json_encode($response));
            if (isset($response['result']) && $response['result'] === 'success') {
                return true;
            }
            $err = isset($response['message']) ? $response['message'] : 'unknown error';
            self::log($clientId, 'email_failed', 'Template send: ' . $err);
        } catch (\Throwable $e) {
            // Catch Error too (e.g. missing WHMCS\Mail\Entity\Client class).
            self::debug($clientId, 'template send threw: ' . $e->getMessage());
            self::log($clientId, 'email_failed', 'Template send exception: ' . $e->getMessage());
        }

        // ---- Path 2: native inline general message -------------------------
        try {
            $response = localAPI('SendEmail', array(
                'customtype'    => 'general',
                'customsubject' => 'Your login verification code',
                'custommessage' => self::inlineEmailBody(),
                'id'            => $clientId,
                'customvars'    => base64_encode(serialize($merge)),
            ));
            self::debug($clientId, 'inline send response: ' . json_encode($response));
            if (isset($response['result']) && $response['result'] === 'success') {
                return true;
            }
            $err = isset($response['message']) ? $response['message'] : 'unknown error';
            self::log($clientId, 'email_failed', 'Inline send: ' . $err);
        } catch (\Throwable $e) {
            self::debug($clientId, 'inline send threw: ' . $e->getMessage());
            self::log($clientId, 'email_failed', 'Inline send exception: ' . $e->getMessage());
        }

        return false;
    }

    /**
     * Inline HTML body used by the native fallback send. Mirrors the stored
     * template so the client gets the same email either way.
     */
    public static function inlineEmailBody()
    {
        return '<p>Hello {$client_name},</p>'
            . '<p>A login to your account was requested. Use the verification code below to continue:</p>'
            . '<p style="font-size:26px;font-weight:bold;letter-spacing:4px;">{$clv_code}</p>'
            . '<p>This code expires in {$clv_expiry} minutes.</p>'
            . '<p><strong>Request details</strong><br>'
            . 'Time: {$clv_datetime}<br>'
            . 'IP address: {$clv_ip}<br>'
            . 'Browser: {$clv_browser}<br>'
            . 'Operating system: {$clv_os}</p>'
            . '<p>If you did not try to log in, please change your password immediately.</p>'
            . '<p>Regards,<br>{$company_name}</p>';
    }

    /**
     * Debug logging. Only records when the debugMode setting is on. Writes to
     * both the module log table and the WHMCS activity log so the exact
     * SendEmail response can be inspected while troubleshooting.
     */
    public static function debug($clientId, $message)
    {
        if (self::setting('debugMode') !== 'on') {
            return;
        }
        try {
            \WHMCS\Database\Capsule::table(self::T_LOGS)->insert(array(
                'client_id'  => (int) $clientId,
                'event'      => 'debug',
                'ip'         => self::ip(),
                'user_agent' => self::userAgent(),
                'message'    => substr((string) $message, 0, 500),
                'created_at' => self::dbNow(),
            ));
        } catch (\Exception $e) {
            // ignore
        }
        if (function_exists('logActivity')) {
            logActivity('[ClientLoginVerify] client#' . (int) $clientId . ' ' . $message);
        }
    }

    /**
     * Send a code and return a rich diagnostic array for the admin test tool.
     *
     * @return array array('ok' => bool, 'message' => string)
     */
    public static function sendCodeDiagnostic($clientId, $code)
    {
        $clientId = (int) $clientId;
        if ($clientId <= 0) {
            return array('ok' => false, 'message' => 'Enter a valid client ID.');
        }

        try {
            $client = \WHMCS\Database\Capsule::table('tblclients')->where('id', $clientId)->first();
        } catch (\Exception $e) {
            return array('ok' => false, 'message' => 'Database error: ' . $e->getMessage());
        }
        if (!$client) {
            return array('ok' => false, 'message' => 'No client found with ID ' . $clientId . '.');
        }

        $template = self::templateName();
        try {
            $exists = \WHMCS\Database\Capsule::table('tblemailtemplates')
                ->where('name', $template)
                ->exists();
        } catch (\Exception $e) {
            $exists = false;
        }
        if (!$exists) {
            return array('ok' => false, 'message' => 'Email template "' . $template . '" was not found. Deactivate and reactivate the module to recreate it, or set the correct template name in settings.');
        }

        if (!function_exists('localAPI')) {
            return array('ok' => false, 'message' => 'WHMCS localAPI() is not available in this context.');
        }

        // Reuse the same native two-path sender used at login so the test
        // exercises the real code path (including the inline fallback that
        // works around the WHMCS\Mail\Entity\Client factory error).
        if (self::sendCode($clientId, $code)) {
            return array('ok' => true, 'message' => 'sent');
        }

        // Surface the most recent failure reason from the log for context.
        try {
            $last = \WHMCS\Database\Capsule::table(self::T_LOGS)
                ->where('client_id', $clientId)
                ->where('event', 'email_failed')
                ->orderBy('id', 'desc')
                ->value('message');
        } catch (\Exception $e) {
            $last = '';
        }
        return array('ok' => false, 'message' => $last ? $last : 'Email could not be sent. Enable Debug Mode and check the Logs tab.');
    }

    public static function browser($agent)
    {
        $agent = strtolower((string) $agent);
        if ($agent === '') {
            return 'Unknown';
        }
        if (strpos($agent, 'edg') !== false)     { return 'Edge'; }
        if (strpos($agent, 'opr') !== false)     { return 'Opera'; }
        if (strpos($agent, 'opera') !== false)   { return 'Opera'; }
        if (strpos($agent, 'chrome') !== false)  { return 'Chrome'; }
        if (strpos($agent, 'firefox') !== false) { return 'Firefox'; }
        if (strpos($agent, 'safari') !== false)  { return 'Safari'; }
        return 'Unknown';
    }

    public static function os($agent)
    {
        $agent = strtolower((string) $agent);
        if ($agent === '') {
            return 'Unknown';
        }
        if (strpos($agent, 'windows') !== false) { return 'Windows'; }
        if (strpos($agent, 'iphone') !== false)  { return 'iOS'; }
        if (strpos($agent, 'ipad') !== false)    { return 'iOS'; }
        if (strpos($agent, 'mac os') !== false)  { return 'macOS'; }
        if (strpos($agent, 'android') !== false) { return 'Android'; }
        if (strpos($agent, 'linux') !== false)   { return 'Linux'; }
        return 'Unknown';
    }

    /* ------------------------------------------------------------------
     * Logging
     * ------------------------------------------------------------------ */

    public static function log($clientId, $event, $message = null)
    {
        if (self::setting('logAttempts') !== 'on') {
            return;
        }

        try {
            \WHMCS\Database\Capsule::table(self::T_LOGS)->insert(array(
                'client_id'  => (int) $clientId,
                'event'      => substr((string) $event, 0, 50),
                'ip'         => (self::setting('logIp') === 'on') ? self::ip() : null,
                'user_agent' => self::userAgent(),
                'message'    => ($message === null) ? null : substr((string) $message, 0, 500),
                'created_at' => self::dbNow(),
            ));
        } catch (\Exception $e) {
            // Logging must never break a login.
        }
    }

    /* ------------------------------------------------------------------
     * Maintenance
     * ------------------------------------------------------------------ */

    public static function cleanup()
    {
        try {
            $codeCutoff = self::dbMinusSeconds(self::KEEP_CODES_DAYS * 86400);
            $logCutoff  = self::dbMinusSeconds(self::KEEP_LOGS_DAYS * 86400);

            \WHMCS\Database\Capsule::table(self::T_CODES)
                ->where('created_at', '<', $codeCutoff)
                ->delete();

            \WHMCS\Database\Capsule::table(self::T_LOGS)
                ->where('created_at', '<', $logCutoff)
                ->delete();
        } catch (\Exception $e) {
            // non fatal
        }
    }

    /* ------------------------------------------------------------------
     * Stats for the admin dashboard
     * ------------------------------------------------------------------ */

    public static function stats()
    {
        $stats = array(
            'pending'   => 0,
            'verified'  => 0,
            'failed'    => 0,
            'totalLogs' => 0,
        );

        try {
            $stats['pending'] = \WHMCS\Database\Capsule::table(self::T_CODES)
                ->whereNull('verified_at')
                ->where('expires_at', '>', self::dbNow())
                ->count();

            $dayAgo = self::dbMinusSeconds(86400);

            $stats['verified'] = \WHMCS\Database\Capsule::table(self::T_LOGS)
                ->where('event', 'verified')
                ->where('created_at', '>', $dayAgo)
                ->count();

            $stats['failed'] = \WHMCS\Database\Capsule::table(self::T_LOGS)
                ->where('event', 'failed')
                ->where('created_at', '>', $dayAgo)
                ->count();

            $stats['totalLogs'] = \WHMCS\Database\Capsule::table(self::T_LOGS)->count();
        } catch (\Exception $e) {
            // Leave zeros if the tables are not ready yet.
        }

        return $stats;
    }

    /**
     * Base URL for module assets, used for the admin logo.
     */
    public static function assetUrl($file)
    {
        $base = '';

        try {
            if (function_exists('select_query') && function_exists('mysql_fetch_assoc')) {
                $result = select_query('tblconfiguration', 'value', array('setting' => 'SystemURL'));
                if ($result) {
                    $row = mysql_fetch_assoc($result);
                    if ($row && !empty($row['value'])) {
                        $base = rtrim($row['value'], '/');
                    }
                }
            }
        } catch (\Exception $e) {
            $base = '';
        }

        if ($base === '' && isset($_SERVER['HTTP_HOST'])) {
            $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
            $base   = ($secure ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'];
        }

        return $base . '/modules/addons/' . self::MODULE . '/' . ltrim($file, '/');
    }
}

}
