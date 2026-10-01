<?php

declare(strict_types=1);

namespace App\Core;

use PDO;
use PDOException;

final class Database
{
    private static ?PDO $pdo = null;

    public static function connection(): PDO
    {
        if (self::$pdo === null) {
            $host = (string) \App\Config\Config::get('DB_HOST', 'db');
            $port = (string) \App\Config\Config::get('DB_PORT', '3306');
            $name = (string) \App\Config\Config::get('DB_DATABASE', 'unifi_access');
            $user = (string) \App\Config\Config::get('DB_USERNAME', 'unifi');
            $pass = (string) \App\Config\Config::get('DB_PASSWORD', '');
            $dsn = "mysql:host={$host};port={$port};dbname={$name};charset=utf8mb4";

            try {
                self::$pdo = new PDO($dsn, $user, $pass, [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES => false,
                    PDO::ATTR_STRINGIFY_FETCHES => false,
                ]);
            } catch (PDOException $e) {
                Logger::error('database', 'Connection failed: ' . $e->getMessage());
                http_response_code(500);
                echo 'Database connection failed. Please check your configuration.';
                exit;
            }
        }

        return self::$pdo;
    }

    /**
     * Run $fn inside a transaction (committed on success, rolled back on any
     * exception). Nested calls join the already running transaction.
     *
     * @template T
     * @param callable():T $fn
     * @return T
     */
    public static function transaction(callable $fn): mixed
    {
        $pdo = self::connection();
        if ($pdo->inTransaction()) {
            return $fn();
        }

        $pdo->beginTransaction();
        try {
            $result = $fn();
            $pdo->commit();
            return $result;
        } catch (\Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }
    }

    /**
     * Acquire a named, server-wide advisory lock (MySQL/MariaDB GET_LOCK).
     * The lock is bound to the DB session and released automatically when
     * the process ends.
     */
    public static function acquireLock(string $name, int $timeoutSeconds = 0): bool
    {
        $stmt = self::connection()->prepare('SELECT GET_LOCK(:n, :t)');
        $stmt->execute(['n' => $name, 't' => $timeoutSeconds]);
        return (int) $stmt->fetchColumn() === 1;
    }

    public static function releaseLock(string $name): void
    {
        self::connection()->prepare('SELECT RELEASE_LOCK(:n)')->execute(['n' => $name]);
    }

    /**
     * Run $fn while holding the named lock. Returns null without calling $fn
     * when the lock could not be acquired within the timeout.
     *
     * @template T
     * @param callable():T $fn
     * @return T|null
     */
    public static function withLock(string $name, int $timeoutSeconds, callable $fn): mixed
    {
        if (!self::acquireLock($name, $timeoutSeconds)) {
            return null;
        }
        try {
            return $fn();
        } finally {
            self::releaseLock($name);
        }
    }
}
