<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;
use PDO;

/**
 * Read-side queries over the local UniFi cache tables, including list,
 * filter, search, pagination and aggregation helpers.
 */
final class CatalogRepository
{
    /**
     * @return array{items:array<int,array<string,mixed>>,total:int}
     */
    public function listPersons(array $filters = []): array
    {
        $pdo = Database::connection();
        [$where, $params] = $this->personWhere($filters);

        $countSql = "SELECT COUNT(*) FROM unifi_users u JOIN unifi_connections c ON c.id = u.connection_id {$where}";
        $stmt = $pdo->prepare($countSql);
        $stmt->execute($params);
        $total = (int) $stmt->fetchColumn();

        $order = $this->personOrder($filters['sort'] ?? null);
        $page = max(1, (int) ($filters['page'] ?? 1));
        $pageSize = max(1, (int) ($filters['page_size'] ?? 25));
        $offset = ($page - 1) * $pageSize;

        $sql = "SELECT
                    u.id, u.connection_id, u.unifi_id, u.first_name, u.last_name, u.full_name,
                    u.email, u.employee_number, u.status, u.onboard_time, u.access_policy_ids_json,
                    u.last_synced_at, c.name AS connection_name,
                    (SELECT GROUP_CONCAT(cr.display_id ORDER BY cr.id SEPARATOR ', ')
                       FROM unifi_credentials cr
                      WHERE cr.connection_id = u.connection_id AND cr.user_unifi_id = u.unifi_id) AS cards
                FROM unifi_users u
                JOIN unifi_connections c ON c.id = u.connection_id
                {$where}
                {$order}
                LIMIT :limit OFFSET :offset";

        $stmt = $pdo->prepare($sql);
        foreach ($params as $k => $v) {
            $stmt->bindValue($k, $v);
        }
        $stmt->bindValue('limit', $pageSize, PDO::PARAM_INT);
        $stmt->bindValue('offset', $offset, PDO::PARAM_INT);
        $stmt->execute();

        return ['items' => $stmt->fetchAll(), 'total' => $total];
    }

    public function getPerson(int $connectionId, string $unifiId): ?array
    {
        $stmt = Database::connection()->prepare(
            'SELECT u.*, c.name AS connection_name
             FROM unifi_users u JOIN unifi_connections c ON c.id = u.connection_id
             WHERE u.connection_id = :c AND u.unifi_id = :u'
        );
        $stmt->execute(['c' => $connectionId, 'u' => $unifiId]);
        return $stmt->fetch() ?: null;
    }

    /** @return array<int,array<string,mixed>> */
    public function credentialsForPerson(int $connectionId, string $unifiId): array
    {
        $stmt = Database::connection()->prepare(
            'SELECT * FROM unifi_credentials WHERE connection_id = :c AND user_unifi_id = :u ORDER BY id ASC'
        );
        $stmt->execute(['c' => $connectionId, 'u' => $unifiId]);
        return $stmt->fetchAll();
    }

    /**
     * @return array{items:array<int,array<string,mixed>>,total:int}
     */
    public function listCredentials(array $filters = []): array
    {
        $pdo = Database::connection();
        $where = [];
        $params = [];

        if (!empty($filters['connection_id'])) {
            $where[] = 'cr.connection_id = :cid';
            $params['cid'] = (int) $filters['connection_id'];
        }
        if (!empty($filters['status'])) {
            $where[] = 'cr.status = :status';
            $params['status'] = $filters['status'];
        }
        if (($filters['card_filter'] ?? null) === 'free') {
            $where[] = '(cr.user_unifi_id IS NULL OR cr.user_unifi_id = "")';
        }
        if (($filters['card_filter'] ?? null) === 'assigned') {
            $where[] = '(cr.user_unifi_id IS NOT NULL AND cr.user_unifi_id != "")';
        }
        if (!empty($filters['search'])) {
            $like = '%' . self::escapeLike((string) $filters['search']) . '%';
            $where[] = '(cr.display_id LIKE :s1 OR cr.unifi_token LIKE :s2 OR cr.alias LIKE :s3 OR u.full_name LIKE :s4)';
            $params['s1'] = $like;
            $params['s2'] = $like;
            $params['s3'] = $like;
            $params['s4'] = $like;
        }

        $whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

        $stmt = $pdo->prepare(
            "SELECT COUNT(*) FROM unifi_credentials cr
             LEFT JOIN unifi_users u ON u.connection_id = cr.connection_id AND u.unifi_id = cr.user_unifi_id
             {$whereSql}"
        );
        $stmt->execute($params);
        $total = (int) $stmt->fetchColumn();

        $page = max(1, (int) ($filters['page'] ?? 1));
        $pageSize = max(1, (int) ($filters['page_size'] ?? 25));
        $offset = ($page - 1) * $pageSize;

        $sql = "SELECT cr.*, c.name AS connection_name, u.full_name AS person_name, u.status AS person_status
                FROM unifi_credentials cr
                JOIN unifi_connections c ON c.id = cr.connection_id
                LEFT JOIN unifi_users u ON u.connection_id = cr.connection_id AND u.unifi_id = cr.user_unifi_id
                {$whereSql}
                ORDER BY cr.id ASC
                LIMIT :limit OFFSET :offset";

        $stmt = $pdo->prepare($sql);
        foreach ($params as $k => $v) {
            $stmt->bindValue($k, $v);
        }
        $stmt->bindValue('limit', $pageSize, PDO::PARAM_INT);
        $stmt->bindValue('offset', $offset, PDO::PARAM_INT);
        $stmt->execute();

        return ['items' => $stmt->fetchAll(), 'total' => $total];
    }

    public function getCredential(int $connectionId, string $token): ?array
    {
        $stmt = Database::connection()->prepare('SELECT * FROM unifi_credentials WHERE connection_id = :c AND unifi_token = :t');
        $stmt->execute(['c' => $connectionId, 't' => $token]);
        return $stmt->fetch() ?: null;
    }

    /** @return array<int,array<string,mixed>> */
    public function listAccessGroups(?int $connectionId = null): array
    {
        $sql = 'SELECT * FROM unifi_access_groups';
        $params = [];
        if ($connectionId !== null) {
            $sql .= ' WHERE connection_id = :c';
            $params['c'] = $connectionId;
        }
        $sql .= ' ORDER BY name ASC';
        $stmt = Database::connection()->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    public function getAccessGroup(int $connectionId, string $unifiId): ?array
    {
        $stmt = Database::connection()->prepare('SELECT * FROM unifi_access_groups WHERE connection_id = :c AND unifi_id = :u');
        $stmt->execute(['c' => $connectionId, 'u' => $unifiId]);
        return $stmt->fetch() ?: null;
    }

    /** @return array<int,array<string,mixed>> */
    public function listDoors(?int $connectionId = null): array
    {
        $sql = 'SELECT d.*, c.name AS connection_name FROM unifi_doors d JOIN unifi_connections c ON c.id = d.connection_id';
        $params = [];
        if ($connectionId !== null) {
            $sql .= ' WHERE d.connection_id = :c';
            $params['c'] = $connectionId;
        }
        $sql .= ' ORDER BY d.name ASC';
        $stmt = Database::connection()->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    /** @return array<int,array<string,mixed>> */
    public function listConnections(): array
    {
        return Database::connection()->query('SELECT * FROM unifi_connections ORDER BY name ASC')->fetchAll();
    }

    /**
     * Per-location aggregate counts used by the dashboard and location list.
     *
     * @return array<int,array<string,mixed>>
     */
    public function locationStats(): array
    {
        $pdo = Database::connection();
        $stats = [];
        $connections = $pdo->query('SELECT id, name FROM unifi_connections ORDER BY name ASC')->fetchAll();
        foreach ($connections as $c) {
            $id = (int) $c['id'];
            $stats[] = [
                'id' => $id,
                'name' => $c['name'],
                'users' => $this->count($pdo, 'unifi_users', $id),
                'credentials' => $this->count($pdo, 'unifi_credentials', $id),
                'doors' => $this->count($pdo, 'unifi_doors', $id),
                'groups' => $this->count($pdo, 'unifi_access_groups', $id),
            ];
        }
        return $stats;
    }

    /** @return array<int,string> flat list of access-policy ids assigned to cached users */
    public function listAllUserPolicyIds(?int $connectionId = null): array
    {
        $sql = 'SELECT access_policy_ids_json FROM unifi_users';
        $params = [];
        if ($connectionId !== null) {
            $sql .= ' WHERE connection_id = :c';
            $params['c'] = $connectionId;
        }
        $stmt = Database::connection()->prepare($sql);
        $stmt->execute($params);
        $ids = [];
        foreach ($stmt->fetchAll() as $row) {
            $decoded = json_decode($row['access_policy_ids_json'] ?? '[]', true);
            if (is_array($decoded)) {
                foreach ($decoded as $id) {
                    if (is_scalar($id)) {
                        $ids[] = (string) $id;
                    }
                }
            }
        }
        return $ids;
    }

    private function count(PDO $pdo, string $table, int $connectionId): int
    {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM {$table} WHERE connection_id = :c");
        $stmt->execute(['c' => $connectionId]);
        return (int) $stmt->fetchColumn();
    }

    private function personWhere(array $filters): array
    {
        $where = [];
        $params = [];

        if (!empty($filters['connection_id'])) {
            $where[] = 'u.connection_id = :cid';
            $params['cid'] = (int) $filters['connection_id'];
        }
        if (!empty($filters['status'])) {
            $where[] = 'u.status = :status';
            $params['status'] = $filters['status'];
        }
        if (!empty($filters['group_id'])) {
            $where[] = 'u.access_policy_ids_json LIKE :gid';
            // FIX: LIKE wildcards (% _) in the group id are escaped.
            $params['gid'] = '%"' . self::escapeLike((string) $filters['group_id']) . '"%';
        }
        if (($filters['card_filter'] ?? null) === 'assigned') {
            $where[] = 'EXISTS (SELECT 1 FROM unifi_credentials cr WHERE cr.connection_id = u.connection_id AND cr.user_unifi_id = u.unifi_id)';
        }
        if (($filters['card_filter'] ?? null) === 'free') {
            $where[] = 'NOT EXISTS (SELECT 1 FROM unifi_credentials cr WHERE cr.connection_id = u.connection_id AND cr.user_unifi_id = u.unifi_id)';
        }
        if (!empty($filters['search'])) {
            $like = '%' . self::escapeLike((string) $filters['search']) . '%';
            $where[] = '(u.full_name LIKE :s1 OR u.first_name LIKE :s2 OR u.last_name LIKE :s3 OR u.email LIKE :s4 OR u.employee_number LIKE :s5
                OR EXISTS (SELECT 1 FROM unifi_credentials cr WHERE cr.connection_id = u.connection_id AND cr.user_unifi_id = u.unifi_id
                    AND (cr.display_id LIKE :s6 OR cr.unifi_token LIKE :s7)))';
            $params['s1'] = $like;
            $params['s2'] = $like;
            $params['s3'] = $like;
            $params['s4'] = $like;
            $params['s5'] = $like;
            $params['s6'] = $like;
            $params['s7'] = $like;
        }

        return [$where ? 'WHERE ' . implode(' AND ', $where) : '', $params];
    }

    /** Escape LIKE meta characters so user input matches literally. */
    public static function escapeLike(string $value): string
    {
        return addcslashes($value, '\\%_');
    }

    private function personOrder(?string $sort): string
    {
        return match ($sort) {
            'status' => 'ORDER BY u.status ASC, u.full_name ASC',
            'email' => 'ORDER BY u.email ASC',
            'newest' => 'ORDER BY u.last_synced_at DESC',
            default => 'ORDER BY u.full_name ASC',
        };
    }
}
