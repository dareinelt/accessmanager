<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;
use App\Security\Crypto;
use PDO;

final class ConnectionRepository
{
    /** @return array<int,array<string,mixed>> */
    public function all(bool $includeInactive = true): array
    {
        $pdo = Database::connection();
        $sql = 'SELECT * FROM unifi_connections';
        if (!$includeInactive) {
            $sql .= ' WHERE is_active = 1';
        }
        $sql .= ' ORDER BY name ASC';
        return $pdo->query($sql)->fetchAll();
    }

    public function find(int $id): ?array
    {
        $stmt = Database::connection()->prepare('SELECT * FROM unifi_connections WHERE id = :id');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public function create(string $name, string $host, int $port, string $token, bool $verifySsl): int
    {
        $pdo = Database::connection();
        $stmt = $pdo->prepare(
            'INSERT INTO unifi_connections (name, host, port, api_token_enc, verify_ssl, is_active)
             VALUES (:name, :host, :port, :enc, :ssl, 1)'
        );
        $stmt->execute([
            'name' => $name,
            'host' => $host,
            'port' => $port,
            'enc' => Crypto::encrypt($token),
            'ssl' => $verifySsl ? 1 : 0,
        ]);
        return (int) $pdo->lastInsertId();
    }

    public function update(int $id, array $data): void
    {
        $fields = [];
        $params = ['id' => $id];
        $allowed = ['name' => 'name', 'host' => 'host', 'port' => 'port', 'is_active' => 'is_active'];
        foreach ($allowed as $col => $key) {
            if (array_key_exists($key, $data)) {
                $fields[] = "{$col} = :{$key}";
                $params[$key] = $data[$key];
            }
        }
        if (array_key_exists('verify_ssl', $data)) {
            $fields[] = 'verify_ssl = :verify_ssl';
            $params['verify_ssl'] = $data['verify_ssl'] ? 1 : 0;
        }
        if (!empty($data['api_token'])) {
            $fields[] = 'api_token_enc = :api_token_enc';
            $params['api_token_enc'] = Crypto::encrypt((string) $data['api_token']);
        }
        if (!$fields) {
            return;
        }
        $sql = 'UPDATE unifi_connections SET ' . implode(', ', $fields) . ' WHERE id = :id';
        Database::connection()->prepare($sql)->execute($params);
    }

    public function delete(int $id): void
    {
        Database::connection()->prepare('DELETE FROM unifi_connections WHERE id = :id')->execute(['id' => $id]);
    }

    public function count(): int
    {
        return (int) Database::connection()->query('SELECT COUNT(*) FROM unifi_connections')->fetchColumn();
    }
}
