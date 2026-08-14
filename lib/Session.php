<?php
/**
 * Client Login Verify - Session wrapper
 * Wrapper around WHMCS\Session for consistent session handling.
 */

namespace ClientLoginVerify;

class Session
{
    public static function get($key)
    {
        return \WHMCS\Session::get($key);
    }

    public static function set($key, $value)
    {
        return \WHMCS\Session::set($key, $value);
    }

    public static function delete($key)
    {
        return \WHMCS\Session::delete($key);
    }
}
