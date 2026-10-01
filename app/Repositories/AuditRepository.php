<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;
use PDO;

final class AuditRepository
{
    public function log(string $action, ?string $entityType = null, ?string $entityId = null, ?string $entityLabel = null, string $result = 'success', ?array $details = null, ?int $userId = null, ?string $username = null, ?string $ip = null): void
    {
        $pdo = Database::connection();
        $stmt = $pdo->prepare(
            'INSERT INTO audit_logs (user_id, username, action, entity_type, entity_id, entity_label, result, ip_address, details)
             VALUES (:uid, :uname, :action, :etype, :eid, :elabel, :result, :ip, :details)'
        );
        $stmt->execute([
            'uid' => $userId,
            'uname' => self::clip($username, 64),
            'action' => (string) self::clip($action, 128),
            'etype' => self::clip($entityType, 64),
            'eid' => self::clip($entityId, 128),
            'elabel' => self::clip($entityLabel, 255),
            'result' => (string) self::clip($result, 16),
            'ip' => self::clip($ip, 45),
            'details' => $details ? json_encode($details, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE) : null,
        ]);
    }

    /** FIX: values longer than the column caused "Data too long" (HTTP 500). */
    private static function clip(?string $value, int $length): ?string
    {
        return $value === null ? null : mb_substr($value, 0, $length);
    }

    /** @return array{items:array<int,array<string,mixed>>,total:int} */
    public function list(int $page, int $pageSize, ?string $filter = null): array
    {
        $pdo = Database::connection();
        $where = '';
        $params = [];
        if ($filter !== null && $filter !== '') {
            $like = '%' . CatalogRepository::escapeLike($filter) . '%';
            $where = 'WHERE (action LIKE :f1 OR entity_label LIKE :f2 OR username LIKE :f3)';
            $params['f1'] = $like;
            $params['f2'] = $like;
            $params['f3'] = $like;
        }

        $stmt = $pdo->prepare("SELECT COUNT(*) FROM audit_logs {$where}");
        $stmt->execute($params);
        $total = (int) $stmt->fetchColumn();

        $offset = ($page - 1) * $pageSize;
        $stmt = $pdo->prepare(
            "SELECT * FROM audit_logs {$where} ORDER BY created_at DESC, id DESC LIMIT :limit OFFSET :offset"
        );
        foreach ($params as $k => $v) {
            $stmt->bindValue($k, $v);
        }
        $stmt->bindValue('limit', $pageSize, PDO::PARAM_INT);
        $stmt->bindValue('offset', $offset, PDO::PARAM_INT);
        $stmt->execute();

        return ['items' => $stmt->fetchAll(), 'total' => $total];
    }
}
