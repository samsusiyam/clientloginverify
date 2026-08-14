<?php
/**
 * Client Login Verify - Time utilities
 * Centralized UTC time handling to avoid PHP/database timezone mismatches.
 */

namespace ClientLoginVerify;

class Time
{
    public static function now(): \DateTime
    {
        return new \DateTime('now', new \DateTimeZone('UTC'));
    }

    public static function dbNow(): string
    {
        return self::now()->format('Y-m-d H:i:s');
    }

    public static function dbExpires(int $minutes): string
    {
        return self::now()->modify("+{$minutes} minutes")->format('Y-m-d H:i:s');
    }

    public static function isExpired(string $expiresAt): bool
    {
        $exp = \DateTime::createFromFormat('Y-m-d H:i:s', $expiresAt, new \DateTimeZone('UTC'));
        if (!$exp) {
            return true;
        }
        return $exp < self::now();
    }

    public static function timestamp(): int
    {
        return self::now()->getTimestamp();
    }

    public static function displayNow(): string
    {
        return self::now()->format('d F Y h:i A');
    }

    public static function dbFromTimestamp(int $timestamp): string
    {
        return (new \DateTime("@{$timestamp}", new \DateTimeZone('UTC')))->format('Y-m-d H:i:s');
    }
}