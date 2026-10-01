<?php

declare(strict_types=1);

namespace App\Auth;

use App\Core\Database;
use PDO;

/**
 * Simple, DB-backed brute-force protection for the login endpoint.
 *
 * Two independent limits apply: per account identifier (protects a single
 * account) and per client IP (protects against password spraying across
 * many accounts).
 */
final class RateLimiter
{
    private const MAX_ATTEMPTS = 5;
    private const MAX_ATTEMPTS_PER_IP = 20;
    private const WINDOW_SECONDS = 900;

    public static function tooManyAttempts(string $identifier): bool
    {
        return self::failures('identifier', $identifier) >= self::MAX_ATTEMPTS;
    }

    public static function tooManyAttemptsFromIp(string $ip): bool
    {
        return self::failures('ip_address', $ip) >= self::MAX_ATTEMPTS_PER_IP;
    }

    public static function record(string $identifier, string $ip, bool $success): void
    {
        $stmt = Database::connection()->prepare('INSERT INTO login_attempts (identifier, ip_address, success) VALUES (:id, :ip, :s)');
        $stmt->execute([
            'id' => mb_substr($identifier, 0, 255),
            'ip' => mb_substr($ip, 0, 45),
            's' => $success ? 1 : 0,
        ]);
    }

    public static function clear(string $identifier): void
    {
        $stmt = Database::connection()->prepare('DELETE FROM login_attempts WHERE identifier = :id');
        $stmt->execute(['id' => $identifier]);
    }

    public static function prune(): void
    {
        Database::connection()->exec('DELETE FROM login_attempts WHERE attempted_at < (NOW() - INTERVAL 1 DAY)');
    }

    /** @param 'identifier'|'ip_address' $column */
    private static function failures(string $column, string $value): int
    {
        $stmt = Database::connection()->prepare(
            "SELECT COUNT(*) FROM login_attempts
             WHERE {$column} = :v AND success = 0
               AND attempted_at > (NOW() - INTERVAL :win SECOND)"
        );
        $stmt->bindValue(':v', $value);
        $stmt->bindValue(':win', self::WINDOW_SECONDS, PDO::PARAM_INT);
        $stmt->execute();

        return (int) $stmt->fetchColumn();
    }
}
