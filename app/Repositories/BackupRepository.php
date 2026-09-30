<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;
use PDO;

/**
 * Data access for backup/restore. Exports the application's configurable
 * data plus the cached UniFi data (the "double bottom" that mirrors what is
 * already present on the Dream Machine) and restores it transactionally by
 * replacing the affected tables.
 */
final class BackupRepository
{
    /**
     * Tables covered by a backup, in restore order. Parent rows are inserted
     * before their dependents; deletion happens in reverse order. Foreign-key
     * checks are disabled during the restore so the snapshot can be replayed
     * with its original primary keys intact.
     */
    public const TABLES = [
        'roles',
        'app_settings',
        'users',
        'unifi_connections',
        'system_secrets',
        'tls_certificates',
        'ad_group_mappings',
        'unifi_users',
        'unifi_credentials',
        'unifi_access_groups',
        'unifi_doors',
    ];

    private const STANDARD_ROLES = [
        ['sysadmin', 'Systemadministrator'],
        ['admin', 'Administrator'],
        ['operator', 'Operator'],
        ['readonly', 'Nur Lesen'],
    ];

    /** @return array<string,list<array<string,mixed>>> */
    public function exportTables(): array
    {
        $pdo = Database::connection();
        $out = [];
        foreach (self::TABLES as $table) {
            $out[$table] = $pdo->query('SELECT * FROM ' . $this->quote($table))->fetchAll();
        }
        return $out;
    }

    /**
     * Replaces every covered table with the content of the given snapshot.
     *
     * @param array<string,list<array<string,mixed>>> $tables
     */
    public function restoreTables(array $tables): void
    {
        $pdo = Database::connection();
        $pdo->beginTransaction();
        try {
            $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
            foreach (array_reverse(self::TABLES) as $table) {
                $pdo->exec('DELETE FROM ' . $this->quote($table));
            }
            foreach (self::TABLES as $table) {
                $this->insertRows($pdo, $table, $tables[$table] ?? []);
            }
            $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
            $pdo->commit();
        } catch (\Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
            throw $exception;
        }
    }

    /** @param list<array<string,mixed>> $rows */
    private function insertRows(PDO $pdo, string $table, array $rows): void
    {
        if ($rows === []) {
            return;
        }
        $columns = $this->columns($table);
        foreach ($rows as $row) {
            if (!is_array($row) || $row === []) {
                continue;
            }
            $keys = array_values(array_intersect(array_keys($row), $columns));
            if ($keys === []) {
                continue;
            }
            $sql = sprintf(
                'INSERT INTO %s (%s) VALUES (%s)',
                $this->quote($table),
                implode(', ', array_map($this->quote(...), $keys)),
                implode(', ', array_map(static fn (string $k): string => ':' . $k, $keys)),
            );
            $stmt = $pdo->prepare($sql);
            $params = [];
            foreach ($keys as $key) {
                $params[$key] = $row[$key];
            }
            $stmt->execute($params);
        }
    }

    /** @return list<string> */
    private function columns(string $table): array
    {
        $stmt = Database::connection()->query('SHOW COLUMNS FROM ' . $this->quote($table));
        $columns = [];
        foreach ($stmt->fetchAll() as $row) {
            $columns[] = (string) $row['Field'];
        }
        return $columns;
    }

    /** Ensure the standard role slugs exist after a restore. */
    public function ensureRoles(): void
    {
        $stmt = Database::connection()->prepare('INSERT IGNORE INTO roles (slug, label) VALUES (:s, :l)');
        foreach (self::STANDARD_ROLES as [$slug, $label]) {
            $stmt->execute(['s' => $slug, 'l' => $label]);
        }
    }

    /** @return array<string,mixed>|null */
    public function findUserRow(int $id): ?array
    {
        $stmt = Database::connection()->prepare('SELECT * FROM users WHERE id = :id');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();
        return is_array($row) ? $row : null;
    }

    /** @return array<string,mixed>|null */
    public function findUserByUsername(string $username): ?array
    {
        $stmt = Database::connection()->prepare('SELECT * FROM users WHERE username = :u LIMIT 1');
        $stmt->execute(['u' => $username]);
        $row = $stmt->fetch();
        return is_array($row) ? $row : null;
    }

    /** @param array<string,mixed> $row */
    public function insertUserRow(array $row): int
    {
        $pdo = Database::connection();
        $stmt = $pdo->prepare(
            'INSERT INTO users (username, email, password_hash, role, is_active)
             VALUES (:u, :e, :p, :r, :a)'
        );
        $stmt->execute([
            'u' => (string) ($row['username'] ?? ''),
            'e' => (string) ($row['email'] ?? ''),
            'p' => (string) ($row['password_hash'] ?? ''),
            'r' => (string) ($row['role'] ?? 'readonly'),
            'a' => !empty($row['is_active']) ? 1 : 0,
        ]);
        return (int) $pdo->lastInsertId();
    }

    private function quote(string $identifier): string
    {
        // Identifiers come from the fixed TABLES allow-list, but we still
        // escape backticks defensively.
        return '`' . str_replace('`', '``', $identifier) . '`';
    }
}
