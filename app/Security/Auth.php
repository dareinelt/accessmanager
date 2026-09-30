<?php

declare(strict_types=1);

namespace App\Security;

use App\Core\Database;
use App\Core\Response;
use App\Core\Session;
use PDO;

final class Auth
{
    public const ROLE_SYSADMIN = 'sysadmin';
    public const ROLE_ADMIN = 'admin';
    public const ROLE_OPERATOR = 'operator';
    public const ROLE_READONLY = 'readonly';

    /** All assignable roles, ordered by privilege (highest first). */
    public const ROLES = [
        self::ROLE_SYSADMIN,
        self::ROLE_ADMIN,
        self::ROLE_OPERATOR,
        self::ROLE_READONLY,
    ];

    /** Privilege ranks; a higher rank satisfies lower-rank role checks. */
    private const RANK = [
        self::ROLE_SYSADMIN => 4,
        self::ROLE_ADMIN => 3,
        self::ROLE_OPERATOR => 2,
        self::ROLE_READONLY => 1,
    ];

    /**
     * Verify credentials and establish a session.
     */
    public static function attempt(string $username, string $password): bool
    {
        $pdo = Database::connection();
        $stmt = $pdo->prepare('SELECT * FROM users WHERE (username = :username OR email = :email) AND is_active = 1 LIMIT 1');
        $stmt->execute(['username' => $username, 'email' => $username]);
        $user = $stmt->fetch();

        if (!$user || !password_verify($password, (string) $user['password_hash'])) {
            return false;
        }

        Session::regenerate(true);
        Session::put('user_id', (int) $user['id']);
        Session::put('role', (string) $user['role']);
        Session::put('username', (string) $user['username']);
        Session::put('logged_in_at', time());

        $upd = $pdo->prepare('UPDATE users SET last_login_at = NOW() WHERE id = :id');
        $upd->execute(['id' => (int) $user['id']]);

        return true;
    }

    public static function check(): bool
    {
        return (int) Session::get('user_id', 0) > 0;
    }

    public static function id(): int
    {
        return (int) Session::get('user_id', 0);
    }

    public static function role(): string
    {
        return (string) Session::get('role', self::ROLE_READONLY);
    }

    public static function username(): string
    {
        return (string) Session::get('username', '');
    }

    public static function user(): ?array
    {
        if (!self::check()) {
            return null;
        }
        $pdo = Database::connection();
        $stmt = $pdo->prepare('SELECT id, username, email, role, is_active, last_login_at, created_at FROM users WHERE id = :id');
        $stmt->execute(['id' => self::id()]);
        return $stmt->fetch() ?: null;
    }

    public static function hasRole(string ...$roles): bool
    {
        $rank = self::RANK[self::role()] ?? 0;
        foreach ($roles as $role) {
            $required = self::RANK[$role] ?? 0;
            if ($required > 0 && $rank >= $required) {
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
            Response::redirect('/dashboard');
        }
    }

    private static function wantsJson(): bool
    {
        return str_contains($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json')
            || str_contains($_SERVER['CONTENT_TYPE'] ?? '', 'application/json');
    }
}
