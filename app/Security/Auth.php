<?php

declare(strict_types=1);

namespace App\Security;

use App\Core\Database;
use App\Core\Response;
use App\Core\Session;

final class Auth
{
    public const ROLE_SYSADMIN = Role::Sysadmin->value;
    public const ROLE_ADMIN = Role::Admin->value;
    public const ROLE_OPERATOR = Role::Operator->value;
    public const ROLE_READONLY = Role::Readonly->value;

    /** All assignable roles, ordered by privilege (highest first). */
    public const ROLES = [
        self::ROLE_SYSADMIN,
        self::ROLE_ADMIN,
        self::ROLE_OPERATOR,
        self::ROLE_READONLY,
    ];

    private const USER_COLUMNS = 'id, username, email, role, is_active, last_login_at, created_at';

    /** Validated user row of the current request (null = not authenticated). */
    private static ?array $current = null;
    private static bool $resolved = false;
    private static ?string $dummyHash = null;

    /**
     * Hash a password with Argon2id (fallback: bcrypt).
     */
    public static function hashPassword(string $plain): string
    {
        return password_hash($plain, defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_BCRYPT);
    }

    /**
     * Verify credentials and establish a session.
     */
    public static function attempt(string $username, string $password): bool
    {
        $pdo = Database::connection();
        $stmt = $pdo->prepare('SELECT * FROM users WHERE (username = :username OR email = :email) LIMIT 1');
        $stmt->execute(['username' => $username, 'email' => $username]);
        $user = $stmt->fetch();

        // SECURITY FIX: always run password_verify() so unknown/disabled
        // accounts cannot be distinguished by response timing.
        $hash = is_array($user) ? (string) $user['password_hash'] : self::dummyHash();
        $valid = password_verify($password, $hash);
        if (!is_array($user) || !$valid || (int) $user['is_active'] !== 1) {
            return false;
        }

        if (password_needs_rehash($hash, defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_BCRYPT)) {
            $pdo->prepare('UPDATE users SET password_hash = :h WHERE id = :id')
                ->execute(['h' => self::hashPassword($password), 'id' => (int) $user['id']]);
        }

        // Session fixation protection + fresh CSRF token for the new identity.
        Session::regenerate(true);
        Session::forget('_csrf');
        Session::put('user_id', (int) $user['id']);
        Session::put('role', (string) $user['role']);
        Session::put('username', (string) $user['username']);
        Session::put('logged_in_at', time());
        Session::put('last_activity', time());

        $pdo->prepare('UPDATE users SET last_login_at = NOW() WHERE id = :id')->execute(['id' => (int) $user['id']]);

        unset($user['password_hash']);
        self::$current = $user;
        self::$resolved = true;

        return true;
    }

    /**
     * SECURITY FIX: the session used to be trusted blindly until logout, so
     * deactivated/deleted accounts and demoted roles kept their privileges.
     * The account is now re-validated against the database on every request
     * and an inactivity timeout is enforced.
     */
    public static function check(): bool
    {
        return self::current() !== null;
    }

    /** @return array<string,mixed>|null */
    private static function current(): ?array
    {
        if (self::$resolved) {
            return self::$current;
        }
        self::$resolved = true;

        $id = (int) Session::get('user_id', 0);
        if ($id <= 0) {
            return null;
        }

        $lastActivity = (int) Session::get('last_activity', 0);
        if ($lastActivity > 0 && time() - $lastActivity > Session::idleTimeout()) {
            Session::restart();
            Session::flash('error', 'Ihre Sitzung ist wegen Inaktivität abgelaufen. Bitte melden Sie sich erneut an.');
            return null;
        }

        $stmt = Database::connection()->prepare('SELECT ' . self::USER_COLUMNS . ' FROM users WHERE id = :id');
        $stmt->execute(['id' => $id]);
        $user = $stmt->fetch();
        if (!is_array($user) || (int) $user['is_active'] !== 1) {
            Session::restart();
            Session::flash('error', 'Ihr Konto ist nicht mehr aktiv. Bitte wenden Sie sich an einen Administrator.');
            return null;
        }

        Session::put('role', (string) $user['role']);
        Session::put('username', (string) $user['username']);
        Session::put('last_activity', time());

        return self::$current = $user;
    }

    /** Drop the per-request cache (after logout or in tests). */
    public static function forget(): void
    {
        self::$current = null;
        self::$resolved = false;
    }

    public static function id(): int
    {
        return (int) Session::get('user_id', 0);
    }

    public static function role(): string
    {
        return (string) Session::get('role', self::ROLE_READONLY);
    }

    public static function roleEnum(): Role
    {
        return Role::tryFrom(self::role()) ?? Role::Readonly;
    }

    public static function username(): string
    {
        return (string) Session::get('username', '');
    }

    public static function user(): ?array
    {
        return self::current();
    }

    public static function hasRole(string ...$roles): bool
    {
        $current = Role::tryFrom(self::role());
        if ($current === null) {
            return false;
        }
        foreach ($roles as $role) {
            $required = Role::tryFrom($role);
            if ($required !== null && $current->satisfies($required)) {
                return true;
            }
        }
        return false;
    }

    public static function requireLogin(): void
    {
        if (!self::check()) {
            if (self::wantsJson()) {
                Response::error('Nicht authentifiziert', 'UNAUTHENTICATED', 401);
            }
            Response::redirect('/login');
        }
    }

    public static function requireRole(string ...$roles): void
    {
        self::requireLogin();
        if (!self::hasRole(...$roles)) {
            if (self::wantsJson()) {
                Response::error('Keine Berechtigung', 'FORBIDDEN', 403);
            }
            Session::flash('error', 'Sie haben keine Berechtigung für diesen Bereich.');
            Response::redirect('/dashboard');
        }
    }

    private static function wantsJson(): bool
    {
        return str_contains($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json')
            || str_contains($_SERVER['CONTENT_TYPE'] ?? '', 'application/json');
    }

    private static function dummyHash(): string
    {
        return self::$dummyHash ??= self::hashPassword(bin2hex(random_bytes(16)));
    }
}
