<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;
use PDO;

/**
 * CRUD + reconciliation access to ad_group_mappings (AD-Gruppe DN
 * <-> UniFi Access Zutrittsgruppe).
 */
final class AdGroupMappingRepository
{
    /** @return array<int,array<string,mixed>> */
    public function list(?int $connectionId = null): array
    {
        $pdo = Database::connection();
        $sql = 'SELECT m.*, c.name AS connection_name, g.name AS access_group_name
                FROM ad_group_mappings m
                JOIN unifi_connections c ON c.id = m.connection_id
                LEFT JOIN unifi_access_groups g ON g.connection_id = m.connection_id AND g.unifi_id = m.access_group_id';
        $params = [];
        if ($connectionId !== null) {
            $sql .= ' WHERE m.connection_id = :c';
            $params['c'] = $connectionId;
        }
        $sql .= ' ORDER BY c.name ASC, m.ad_group_name ASC, m.ad_group_dn ASC';

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    }

    public function find(int $id): ?array
    {
        $stmt = Database::connection()->prepare(
            'SELECT m.*, c.name AS connection_name, g.name AS access_group_name
             FROM ad_group_mappings m
             JOIN unifi_connections c ON c.id = m.connection_id
             LEFT JOIN unifi_access_groups g ON g.connection_id = m.connection_id AND g.unifi_id = m.access_group_id
             WHERE m.id = :id'
        );
        $stmt->execute(['id' => $id]);
        return $stmt->fetch() ?: null;
    }

    public function exists(int $connectionId, string $adGroupDn, ?int $excludeId = null): bool
    {
        $sql = 'SELECT COUNT(*) FROM ad_group_mappings WHERE connection_id = :c AND ad_group_dn = :dn';
        $params = ['c' => $connectionId, 'dn' => $adGroupDn];
        if ($excludeId !== null) {
            $sql .= ' AND id != :id';
            $params['id'] = $excludeId;
        }
        $stmt = Database::connection()->prepare($sql);
        $stmt->execute($params);
        return (int) $stmt->fetchColumn() > 0;
    }

    public function create(int $connectionId, string $adGroupDn, string $accessGroupId, ?string $adGroupName = null): int
    {
        $pdo = Database::connection();
        $pdo->prepare(
            'INSERT INTO ad_group_mappings (connection_id, ad_group_dn, ad_group_name, access_group_id)
             VALUES (:c, :dn, :name, :ag)'
        )->execute([
            'c' => $connectionId,
            'dn' => $adGroupDn,
            'name' => $adGroupName !== null && $adGroupName !== '' ? $adGroupName : null,
            'ag' => $accessGroupId,
        ]);
        return (int) $pdo->lastInsertId();
    }

    public function update(int $id, array $data): void
    {
        $fields = [];
        $params = ['id' => $id];
        foreach (['connection_id' => 'connection_id', 'ad_group_dn' => 'ad_group_dn', 'ad_group_name' => 'ad_group_name', 'access_group_id' => 'access_group_id'] as $col => $key) {
            if (array_key_exists($key, $data)) {
                $fields[] = "{$col} = :{$key}";
                $params[$key] = $data[$key];
            }
        }
        if (!$fields) {
            return;
        }
        Database::connection()->prepare('UPDATE ad_group_mappings SET ' . implode(', ', $fields) . ' WHERE id = :id')
            ->execute($params);
    }

    public function delete(int $id): void
    {
        Database::connection()->prepare('DELETE FROM ad_group_mappings WHERE id = :id')->execute(['id' => $id]);
    }

    /** @return array<string,string> ad_group_dn => access_group_id */
    public function byConnection(int $connectionId): array
    {
        $stmt = Database::connection()->prepare(
            'SELECT ad_group_dn, access_group_id FROM ad_group_mappings WHERE connection_id = :c'
        );
        $stmt->execute(['c' => $connectionId]);
        $map = [];
        foreach ($stmt->fetchAll() as $row) {
            $map[(string) $row['ad_group_dn']] = (string) $row['access_group_id'];
        }
        return $map;
    }

    /** @return array<int,string> distinct managed access_group_id values */
    public function mappedAccessGroupIds(int $connectionId): array
    {
        $stmt = Database::connection()->prepare(
            'SELECT DISTINCT access_group_id FROM ad_group_mappings WHERE connection_id = :c'
        );
        $stmt->execute(['c' => $connectionId]);
        return array_map(static fn (array $r): string => (string) $r['access_group_id'], $stmt->fetchAll());
    }

    /**
     * Reconciliation / drift report: persons that currently hold a mapped
     * UniFi access group but are NOT members of the corresponding AD group
     * (according to the memberships persisted during the last AD sync).
     *
     * @return array<int,array<string,mixed>>
     */
    public function findNonCompliant(?int $connectionId = null): array
    {
        $pdo = Database::connection();
        $params = [];
        $where = '';
        if ($connectionId !== null) {
            $where = ' WHERE m.connection_id = :c';
            $params['c'] = $connectionId;
        }

        $mappingSql = "SELECT m.*, c.name AS connection_name, g.name AS access_group_name
                       FROM ad_group_mappings m
                       JOIN unifi_connections c ON c.id = m.connection_id
                       LEFT JOIN unifi_access_groups g ON g.connection_id = m.connection_id AND g.unifi_id = m.access_group_id{$where}";
        $stmt = $pdo->prepare($mappingSql);
        $stmt->execute($params);
        $mappings = $stmt->fetchAll();

        $personWhere = '';
        if ($connectionId !== null) {
            $personWhere = ' WHERE connection_id = :c';
        }
        $pstmt = $pdo->prepare("SELECT connection_id, unifi_id, full_name, email, access_policy_ids_json, ad_member_of_json FROM unifi_users{$personWhere}");
        $pstmt->execute($params);
        $persons = $pstmt->fetchAll();

        /** @var array<int,array<string,array<string,mixed>>> $byAccess connection_id => access_group_id => mapping */
        $byAccess = [];
        foreach ($mappings as $m) {
            $byAccess[(int) $m['connection_id']][(string) $m['access_group_id']] = $m;
        }

        $rows = [];
        foreach ($persons as $p) {
            $cid = (int) $p['connection_id'];
            if (!isset($byAccess[$cid])) {
                continue;
            }
            $policies = json_decode((string) ($p['access_policy_ids_json'] ?? '[]'), true) ?: [];
            $memberOf = json_decode((string) ($p['ad_member_of_json'] ?? '[]'), true) ?: [];
            foreach ($policies as $policyId) {
                $policyId = (string) $policyId;
                if (!isset($byAccess[$cid][$policyId])) {
                    continue;
                }
                $m = $byAccess[$cid][$policyId];
                if (in_array((string) $m['ad_group_dn'], array_map('strval', $memberOf), true)) {
                    continue;
                }
                $rows[] = [
                    'connection_name' => (string) $m['connection_name'],
                    'full_name' => (string) ($p['full_name'] ?? ''),
                    'email' => (string) ($p['email'] ?? ''),
                    'access_group_name' => (string) ($m['access_group_name'] ?? $m['access_group_id']),
                    'ad_group_name' => (string) ($m['ad_group_name'] ?? $m['ad_group_dn']),
                    'ad_group_dn' => (string) $m['ad_group_dn'],
                ];
            }
        }

        return $rows;
    }
}
