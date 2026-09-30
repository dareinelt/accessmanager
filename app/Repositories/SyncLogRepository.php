<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;
use PDO;

final class SyncLogRepository
{
    public function start(?int $connectionId): int
    {
        $pdo = Database::connection();
        $stmt = $pdo->prepare('INSERT INTO sync_logs (connection_id, status, started_at) VALUES (:c, :s, NOW())');
        $stmt->execute(['c' => $connectionId, 's' => 'running']);
        return (int) $pdo->lastInsertId();
    }

    public function finish(int $id, string $status, ?string $message = null, ?array $stats = null): void
    {
        $pdo = Database::connection();
        $stmt = $pdo->prepare('UPDATE sync_logs SET status = :s, message = :m, stats = :stats, finished_at = NOW() WHERE id = :id');
        $stmt->execute([
            's' => $status,
            'm' => $message,
            'stats' => $stats ? json_encode($stats, JSON_UNESCAPED_UNICODE) : null,
            'id' => $id,
        ]);
    }

    /** @return array<int,array<string,mixed>> */
    public function recent(int $limit = 20): array
    {
        $stmt = Database::connection()->prepare(
            'SELECT s.*, c.name AS connection_name
             FROM sync_logs s
             LEFT JOIN unifi_connections c ON c.id = s.connection_id
             ORDER BY s.created_at DESC, s.id DESC LIMIT :limit'
        );
        $stmt->bindValue('limit', $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    public function lastSuccessful(): ?array
    {
        $stmt = Database::connection()->prepare(
            'SELECT * FROM sync_logs WHERE status = :s ORDER BY finished_at DESC LIMIT 1'
        );
        $stmt->execute(['s' => 'success']);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public function lastFailed(): ?array
    {
        $stmt = Database::connection()->prepare(
            'SELECT * FROM sync_logs WHERE status = :s ORDER BY finished_at DESC LIMIT 1'
        );
        $stmt->execute(['s' => 'failed']);
        $row = $stmt->fetch();
        return $row ?: null;
    }
}
