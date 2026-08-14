<?php
/**
 * Client Login Verify - Session wrapper
 * Thin wrapper around WHMCS\Session with a $_SESSION fallback so the module
 * keeps working across WHMCS 8.x minor versions that may differ in the
 * Session API surface.
 */

namespace ClientLoginVerify;

class Session
{
    public static function get($key)
    {
        if (class_exists('WHMCS\\Session')) {
            return \WHMCS\Session::get($key);
        }
        return $_SESSION[$key] ?? null;
    }

    public static function set($key, $value)
    {
        if (class_exists('WHMCS\\Session')) {
            return \WHMCS\Session::set($key, $value);
        }
        $_SESSION[$key] = $value;
    }

    public static function delete($key)
    {
        if (class_exists('WHMCS\\Session')) {
            return \WHMCS\Session::delete($key);
        }
        unset($_SESSION[$key]);
    }
}
