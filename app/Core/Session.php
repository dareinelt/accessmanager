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

        // SECURITY FIX: strict mode rejects attacker-supplied (uninitialised)
        // session IDs; cookies are the only accepted transport.
        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');
        ini_set('session.use_trans_sid', '0');
        ini_set('session.gc_maxlifetime', (string) self::idleTimeout());

        session_name((string) Config::get('SESSION_NAME', 'uam_session'));
        session_set_cookie_params([
            // FIX: the cookie used to expire SESSION_LIFETIME seconds after
            // login regardless of activity. It is now a browser-session cookie;
            // inactivity is enforced server-side (see Auth::check()).
            'lifetime' => 0,
            'path' => '/',
            'httponly' => true,
            'samesite' => 'Lax',
            'secure' => Request::isSecure($_SERVER),
        ]);
        session_start();
        self::$started = true;
    }

    /** Inactivity timeout in seconds (SESSION_LIFETIME, minimum 5 minutes). */
    public static function idleTimeout(): int
    {
        return max(300, Config::int('SESSION_LIFETIME', 1440));
    }

    public static function regenerate(bool $destroy = false): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_regenerate_id($destroy);
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

    /** Store a one-time message shown on the next rendered page. */
    public static function flash(string $type, string $message): void
    {
        $_SESSION['flash'] = ['type' => $type, 'message' => $message];
    }

    /** @return array{type:string,message:string}|null */
    public static function pullFlash(): ?array
    {
        $flash = $_SESSION['flash'] ?? null;
        unset($_SESSION['flash']);
        return is_array($flash) ? $flash : null;
    }

    public static function destroy(): void
    {
        $_SESSION = [];
        if (session_status() !== PHP_SESSION_ACTIVE) {
            return;
        }
        if (ini_get('session.use_cookies')) {
            $p = session_get_cookie_params();
            setcookie(session_name(), '', [
                'expires' => time() - 42000,
                'path' => $p['path'],
                'domain' => $p['domain'],
                'secure' => $p['secure'],
                'httponly' => $p['httponly'],
                'samesite' => $p['samesite'] ?? 'Lax',
            ]);
        }
        session_destroy();
        self::$started = false;
    }

    /**
     * Destroy the current session and immediately start a fresh, empty one
     * (e.g. to carry a flash message to the login page after a logout).
     */
    public static function restart(): void
    {
        self::destroy();
        if (PHP_SAPI !== 'cli') {
            self::start();
            session_regenerate_id(true);
        }
    }
}
