<?php

// Client releated operations
// @caoxl

namespace App\Traits;

class Client
{
    public static function ip()
    {
        if ($clientIP = self::tryIPKey('HTTP_CLIENT_IP')) {
        } elseif ($clientIP = self::tryIPKey('HTTP_X_FORWARDED_FOR')) {
        } elseif ($clientIP = self::tryIPKey('HTTP_X_FORWARDED')) {
        } elseif ($clientIP = self::tryIPKey('HTTP_FORWARDED_FOR')) {
        } elseif ($clientIP = self::tryIPKey('HTTP_FORWARDED')) {
        } elseif ($clientIP = self::tryIPKey('REMOTE_ADDR')) {
        } else $clientIP = 'UNKNOWN';

        return $clientIP;
    }

    public static function tryIPKey(string $possibleKey)
    {
        return getenv($possibleKey)
            ?? (
                $_SERVER[$possibleKey] ?? null
            );
    }
}
