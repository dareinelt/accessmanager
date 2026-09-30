<?php

declare(strict_types=1);

namespace App\Core;

use App\Config\Config;

final class Session
{
    private static bool $started = false;

    public static function start(): void
    {
        if (self::$started) {
            return;
        }

        $name = (string) Config::get('SESSION_NAME', 'uam_session');
        session_name($name);
        session_set_cookie_params([
            'lifetime' => Config::int('SESSION_LIFETIME', 1440),
            'path' => '/',
            'httponly' => true,
            'samesite' => 'Lax',
            'secure' => self::isSecureRequest(),
        ]);
        session_start();
        self::$started = true;
    }

    public static function regenerate(bool $destroy = false): void
    {
        if ($destroy) {
            session_regenerate_id(true);
        } else {
            session_regenerate_id();
        }
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        return $_SESSION[$key] ?? $default;
    }

    public static function put(string $key, mixed $value): void
    {
        $_SESSION[$key] = $value;
    }

    public static function forget(string $key): void
    {
        unset($_SESSION[$key]);
    }

    public static function destroy(): void
    {
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $p = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
        }
        session_destroy();
        self::$started = false;
    }

    private static function isSecureRequest(): bool
    {
        $https = $_SERVER['HTTPS'] ?? '';
        $proto = $_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '';
        return (!empty($https) && $https !== 'off') || $proto === 'https';
    }
}
