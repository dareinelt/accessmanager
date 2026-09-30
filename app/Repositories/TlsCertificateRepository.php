<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;
use PDO;

/**
 * Zugriff auf die Tabelle tls_certificates (CSRs, importierte Zertifikate,
 * Notfall-Zertifikate).
 */
final class TlsCertificateRepository
{
    /**
     * Alle Eintraege, neueste zuerst.
     *
     * @return list<array<string,mixed>>
     */
    public function all(): array
    {
        $rows = Database::connection()
            ->query('SELECT * FROM tls_certificates ORDER BY created_at DESC, id DESC')
            ->fetchAll();

        return $rows;
    }

    /**
     * @return array<string,mixed>|null
     */
    public function find(int $id): ?array
    {
        $stmt = Database::connection()->prepare('SELECT * FROM tls_certificates WHERE id = :id');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();

        return is_array($row) ? $row : null;
    }

    /**
     * CSR, zu dem ein oeffentlicher Schluessel gehoert (neuester zuerst).
     *
     * @return array<string,mixed>|null
     */
    public function findRequestByPublicKey(string $hash): ?array
    {
        $stmt = Database::connection()->prepare(
            "SELECT * FROM tls_certificates WHERE kind = 'csr' AND public_key_hash = :hash ORDER BY id DESC LIMIT 1"
        );
        $stmt->execute(['hash' => $hash]);
        $row = $stmt->fetch();

        return is_array($row) ? $row : null;
    }

    /**
     * @return array<string,mixed>|null
     */
    public function findActive(): ?array
    {
        $stmt = Database::connection()->query(
            "SELECT * FROM tls_certificates WHERE kind = 'csr' AND active = 1 ORDER BY id DESC LIMIT 1"
        );
        $row = $stmt->fetch();

        return is_array($row) ? $row : null;
    }

    /**
     * @return array<string,mixed>|null
     */
    public function latestFallback(): ?array
    {
        $stmt = Database::connection()->query(
            "SELECT * FROM tls_certificates WHERE kind = 'fallback' ORDER BY id DESC LIMIT 1"
        );
        $row = $stmt->fetch();

        return is_array($row) ? $row : null;
    }

    /**
     * @param array<string,mixed> $values
     */
    public function insert(array $values): int
    {
        $pdo = Database::connection();
        $columns = array_keys($values);
        $stmt = $pdo->prepare(sprintf(
            'INSERT INTO tls_certificates (%s) VALUES (%s)',
            implode(', ', $columns),
            implode(', ', array_map(static fn (string $column): string => ':' . $column, $columns))
        ));
        $stmt->execute($values);

        return (int) $pdo->lastInsertId();
    }

    /**
     * @param array<string,mixed> $values
     */
    public function update(int $id, array $values): void
    {
        if ($values === []) {
            return;
        }

        $assignments = array_map(static fn (string $column): string => $column . ' = :' . $column, array_keys($values));
        $stmt = Database::connection()->prepare(sprintf('UPDATE tls_certificates SET %s WHERE id = :id', implode(', ', $assignments)));
        $stmt->execute($values + ['id' => $id]);
    }

    /**
     * Setzt genau einen Eintrag aktiv (oder keinen bei null).
     */
    public function activate(?int $id, int $now): void
    {
        $pdo = Database::connection();
        $pdo->beginTransaction();
        try {
            $pdo->exec("UPDATE tls_certificates SET active = 0 WHERE kind = 'csr' AND active = 1");
            if ($id !== null) {
                $stmt = $pdo->prepare(
                    "UPDATE tls_certificates SET active = 1, activated_at = :now WHERE id = :id AND kind = 'csr'"
                );
                $stmt->execute(['id' => $id, 'now' => $now]);
            }
            $pdo->commit();
        } catch (\Throwable $exception) {
            $pdo->rollBack();

            throw $exception;
        }
    }

    /**
     * Vermerkt, dass der Webserver diesen Eintrag gerade ausliefert.
     */
    public function markUsed(int $id, int $now): void
    {
        $stmt = Database::connection()->prepare(
            'UPDATE tls_certificates SET last_used_at = :now, first_used_at = COALESCE(first_used_at, :first) WHERE id = :id'
        );
        $stmt->execute(['id' => $id, 'now' => $now, 'first' => $now]);
    }

    public function delete(int $id): void
    {
        Database::connection()->prepare('DELETE FROM tls_certificates WHERE id = :id')->execute(['id' => $id]);
    }
}
