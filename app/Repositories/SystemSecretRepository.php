<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;
use App\Security\Crypto;

/**
 * Persistence for system secrets (AD data, DNs, API endpoints, ...).
 * Values are stored AES-256-GCM encrypted in `value_enc`.
 */
final class SystemSecretRepository
{
    /** @return array<int,array<string,mixed>> */
    public function all(): array
    {
        return Database::connection()->query(
            "SELECT id, `key`, label, category, (LENGTH(value_enc) > 0) AS has_value, created_at, updated_at
             FROM system_secrets ORDER BY category ASC, `key` ASC"
        )->fetchAll();
    }

    /** @return array<string,mixed>|null */
    public function find(int $id): ?array
    {
        $stmt = Database::connection()->prepare('SELECT * FROM system_secrets WHERE id = :id');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    /** @return array<string,mixed>|null */
    public function findByKey(string $key): ?array
    {
        $stmt = Database::connection()->prepare('SELECT * FROM system_secrets WHERE `key` = :k');
        $stmt->execute(['k' => $key]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public function create(string $key, string $label, string $category, string $value): int
    {
        $pdo = Database::connection();
        $stmt = $pdo->prepare(
            'INSERT INTO system_secrets (`key`, label, category, value_enc) VALUES (:k, :l, :c, :v)'
        );
        $stmt->execute([
            'k' => $key,
            'l' => $label,
            'c' => $category,
            'v' => Crypto::encrypt($value),
        ]);
        return (int) $pdo->lastInsertId();
    }

    public function update(int $id, array $data): void
    {
        $fields = [];
        $params = ['id' => $id];
        if (array_key_exists('label', $data)) {
            $fields[] = 'label = :label';
            $params['label'] = $data['label'];
        }
        if (array_key_exists('category', $data)) {
            $fields[] = 'category = :category';
            $params['category'] = $data['category'];
        }
        if (array_key_exists('value', $data) && $data['value'] !== '') {
            $fields[] = 'value_enc = :value_enc';
            $params['value_enc'] = Crypto::encrypt((string) $data['value']);
        }
        if (!$fields) {
            return;
        }
        Database::connection()->prepare('UPDATE system_secrets SET ' . implode(', ', $fields) . ' WHERE id = :id')->execute($params);
    }

    public function delete(int $id): void
    {
        Database::connection()->prepare('DELETE FROM system_secrets WHERE id = :id')->execute(['id' => $id]);
    }
}
