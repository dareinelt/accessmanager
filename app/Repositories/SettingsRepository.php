<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;
use PDO;

final class SettingsRepository
{
    public function get(string $key, ?string $default = null): ?string
    {
        $stmt = Database::connection()->prepare('SELECT `value` FROM app_settings WHERE `key` = :k');
        $stmt->execute(['k' => $key]);
        $value = $stmt->fetchColumn();
        return $value !== false ? (string) $value : $default;
    }

    public function set(string $key, ?string $value): void
    {
        $pdo = Database::connection();
        $pdo->prepare('INSERT INTO app_settings (`key`, `value`) VALUES (:k, :v) ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)')
            ->execute(['k' => $key, 'v' => $value]);
    }

    /** @return array<string,string> */
    public function all(): array
    {
        $rows = Database::connection()->query('SELECT `key`, `value` FROM app_settings')->fetchAll();
        $out = [];
        foreach ($rows as $row) {
            $out[$row['key']] = (string) $row['value'];
        }
        return $out;
    }
}
