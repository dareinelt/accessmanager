<?php

declare(strict_types=1);

namespace App\Auth;

use App\Core\Database;
use PDO;

/**
 * Simple, DB-backed brute-force protection for the login endpoint.
 */
final class RateLimiter
{
    private const MAX_ATTEMPTS = 5;
    private const WINDOW_SECONDS = 900;

    public static function tooManyAttempts(string $identifier): bool
    {
        $pdo = Database::connection();
        $stmt = $pdo->prepare(
            'SELECT COUNT(*) FROM login_attempts
             WHERE identifier = :id AND success = 0
               AND attempted_at > (NOW() - INTERVAL :win SECOND)'
        );
        $stmt->bindValue(':id', $identifier);
        $stmt->bindValue(':win', self::WINDOW_SECONDS, PDO::PARAM_INT);
        $stmt->execute();

        return (int) $stmt->fetchColumn() >= self::MAX_ATTEMPTS;
    }

    public static function record(string $identifier, string $ip, bool $success): void
    {
        $pdo = Database::connection();
        $stmt = $pdo->prepare('INSERT INTO login_attempts (identifier, ip_address, success) VALUES (:id, :ip, :s)');
        $stmt->execute(['id' => $identifier, 'ip' => $ip, 's' => $success ? 1 : 0]);
    }

    public static function clear(string $identifier): void
    {
        $pdo = Database::connection();
        $stmt = $pdo->prepare('DELETE FROM login_attempts WHERE identifier = :id');
        $stmt->execute(['id' => $identifier]);
    }

    public static function prune(): void
    {
        Database::connection()->exec('DELETE FROM login_attempts WHERE attempted_at < (NOW() - INTERVAL 1 DAY)');
    }
}
