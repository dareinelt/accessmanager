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
}
