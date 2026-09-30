<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;
use PDO;

final class AppUserRepository
{
    /** @return array<int,array<string,mixed>> */
    public function all(): array
    {
        return Database::connection()->query(
            'SELECT id, username, email, role, is_active, last_login_at, created_at FROM users ORDER BY username ASC'
        )->fetchAll();
    }

    public function find(int $id): ?array
    {
        $stmt = Database::connection()->prepare('SELECT id, username, email, role, is_active, last_login_at, created_at FROM users WHERE id = :id');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public function findByUsername(string $username): ?array
    {
        $stmt = Database::connection()->prepare('SELECT * FROM users WHERE username = :username OR email = :email LIMIT 1');
        $stmt->execute(['username' => $username, 'email' => $username]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public function create(string $username, string $email, string $password, string $role): int
    {
        $pdo = Database::connection();
        $stmt = $pdo->prepare(
            'INSERT INTO users (username, email, password_hash, role) VALUES (:u, :e, :p, :r)'
        );
        $stmt->execute([
            'u' => $username,
            'e' => $email,
            'p' => password_hash($password, PASSWORD_DEFAULT),
            'r' => $role,
        ]);
        return (int) $pdo->lastInsertId();
    }

    public function update(int $id, array $data): void
    {
        $fields = [];
        $params = ['id' => $id];
        foreach (['email' => 'email', 'role' => 'role'] as $col => $key) {
            if (array_key_exists($key, $data)) {
                $fields[] = "{$col} = :{$key}";
                $params[$key] = $data[$key];
            }
        }
        if (!empty($data['password'])) {
            $fields[] = 'password_hash = :password_hash';
            $params['password_hash'] = password_hash((string) $data['password'], PASSWORD_DEFAULT);
        }
        if (array_key_exists('is_active', $data)) {
            $fields[] = 'is_active = :is_active';
            $params['is_active'] = $data['is_active'] ? 1 : 0;
        }
        if (!$fields) {
            return;
        }
        Database::connection()->prepare('UPDATE users SET ' . implode(', ', $fields) . ' WHERE id = :id')->execute($params);
    }

    public function delete(int $id): void
    {
        Database::connection()->prepare('DELETE FROM users WHERE id = :id')->execute(['id' => $id]);
    }

    public function count(): int
    {
        return (int) Database::connection()->query('SELECT COUNT(*) FROM users')->fetchColumn();
    }
}
