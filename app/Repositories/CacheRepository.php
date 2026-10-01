<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;
use PDO;

/**
 * Read/write access to the local UniFi cache tables. The cache mirrors
 * UniFi data; UniFi remains the source of truth. Every cached row stores
 * the original UniFi ID plus a raw_json snapshot for future needs.
 */
final class CacheRepository
{
    // ------------------------------------------------------------ Users

    public function upsertUser(int $connectionId, array $user): void
    {
        $pdo = Database::connection();
        $sql = 'INSERT INTO unifi_users
                    (connection_id, unifi_id, first_name, last_name, full_name, email, employee_number, status, onboard_time, access_policy_ids_json, raw_json)
                VALUES
                    (:c, :uid, :fn, :ln, :full, :email, :emp, :status, :onboard, :policies, :raw)
                ON DUPLICATE KEY UPDATE
                    first_name = VALUES(first_name), last_name = VALUES(last_name), full_name = VALUES(full_name),
                    email = VALUES(email), employee_number = VALUES(employee_number), status = VALUES(status),
                    onboard_time = VALUES(onboard_time), access_policy_ids_json = VALUES(access_policy_ids_json),
                    raw_json = VALUES(raw_json), last_synced_at = CURRENT_TIMESTAMP';
        $pdo->prepare($sql)->execute($this->userParams($connectionId, $user));
    }

    public function deleteUser(int $connectionId, string $unifiId): void
    {
        Database::connection()->prepare('DELETE FROM unifi_users WHERE connection_id = :c AND unifi_id = :u')
            ->execute(['c' => $connectionId, 'u' => $unifiId]);
    }

    /**
     * Persist the Active Directory identity and memberships that the AD sync
     * has determined for a cached person.
     *
     * @param array<int,string> $memberOf
     */
    public function setUserAdMetadata(int $connectionId, string $unifiId, string $adIdentifier, array $memberOf): void
    {
        Database::connection()->prepare(
            'UPDATE unifi_users
             SET ad_identifier = :ad, ad_member_of_json = :member, ad_synced_at = NOW()
             WHERE connection_id = :c AND unifi_id = :u'
        )->execute([
            'c' => $connectionId,
            'u' => $unifiId,
            'ad' => $adIdentifier !== '' ? $adIdentifier : null,
            'member' => json_encode(array_values($memberOf), JSON_UNESCAPED_UNICODE),
        ]);
    }

    // ------------------------------------------------------ Credentials

    public function upsertCredential(int $connectionId, array $cred): void
    {
        $pdo = Database::connection();
        $sql = 'INSERT INTO unifi_credentials
                    (connection_id, unifi_token, display_id, status, alias, card_type, user_unifi_id, raw_json)
                VALUES
                    (:c, :token, :display, :status, :alias, :type, :user, :raw)
                ON DUPLICATE KEY UPDATE
                    display_id = VALUES(display_id), status = VALUES(status), alias = VALUES(alias),
                    card_type = VALUES(card_type), user_unifi_id = VALUES(user_unifi_id),
                    raw_json = VALUES(raw_json), last_synced_at = CURRENT_TIMESTAMP';
        $pdo->prepare($sql)->execute([
            'c' => $connectionId,
            'token' => (string) ($cred['token'] ?? ''),
            'display' => $cred['display_id'] ?? null,
            'status' => $cred['status'] ?? null,
            'alias' => $cred['alias'] ?? null,
            'type' => $cred['card_type'] ?? null,
            'user' => $cred['user_id'] ?? null,
            'raw' => json_encode($cred, JSON_UNESCAPED_UNICODE),
        ]);
    }

    public function deleteCredential(int $connectionId, string $token): void
    {
        Database::connection()->prepare('DELETE FROM unifi_credentials WHERE connection_id = :c AND unifi_token = :t')
            ->execute(['c' => $connectionId, 't' => $token]);
    }

    // ---------------------------------------------------- Access groups

    public function upsertAccessGroup(int $connectionId, array $group): void
    {
        $pdo = Database::connection();
        $sql = 'INSERT INTO unifi_access_groups
                    (connection_id, unifi_id, name, schedule_id, resources_json, raw_json)
                VALUES
                    (:c, :uid, :name, :schedule, :resources, :raw)
                ON DUPLICATE KEY UPDATE
                    name = VALUES(name), schedule_id = VALUES(schedule_id),
                    resources_json = VALUES(resources_json), raw_json = VALUES(raw_json),
                    last_synced_at = CURRENT_TIMESTAMP';
        $pdo->prepare($sql)->execute([
            'c' => $connectionId,
            'uid' => (string) ($group['id'] ?? ''),
            'name' => (string) ($group['name'] ?? ''),
            'schedule' => $group['schedule_id'] ?? null,
            'resources' => json_encode($group['resources'] ?? [], JSON_UNESCAPED_UNICODE),
            'raw' => json_encode($group, JSON_UNESCAPED_UNICODE),
        ]);
    }

    public function deleteAccessGroup(int $connectionId, string $unifiId): void
    {
        Database::connection()->prepare('DELETE FROM unifi_access_groups WHERE connection_id = :c AND unifi_id = :u')
            ->execute(['c' => $connectionId, 'u' => $unifiId]);
    }

    // ------------------------------------------------------------ Doors

    public function upsertDoor(int $connectionId, array $door): void
    {
        $pdo = Database::connection();
        $sql = 'INSERT INTO unifi_doors
                    (connection_id, unifi_id, name, full_name, floor_id, door_type, lock_status, raw_json)
                VALUES
                    (:c, :uid, :name, :full, :floor, :type, :lock, :raw)
                ON DUPLICATE KEY UPDATE
                    name = VALUES(name), full_name = VALUES(full_name), floor_id = VALUES(floor_id),
                    door_type = VALUES(door_type), lock_status = VALUES(lock_status),
                    raw_json = VALUES(raw_json), last_synced_at = CURRENT_TIMESTAMP';
        $pdo->prepare($sql)->execute([
            'c' => $connectionId,
            'uid' => (string) ($door['id'] ?? ''),
            'name' => (string) ($door['name'] ?? ''),
            'full' => $door['full_name'] ?? ($door['name'] ?? null),
            'floor' => $door['floor_id'] ?? null,
            'type' => $door['type'] ?? null,
            'lock' => $door['door_lock_relay_status'] ?? null,
            'raw' => json_encode($door, JSON_UNESCAPED_UNICODE),
        ]);
    }

    // ---------------------------------------------------------- Reading

    public function countUsers(?int $connectionId = null): int
    {
        $sql = 'SELECT COUNT(*) FROM unifi_users';
        $params = [];
        if ($connectionId !== null) {
            $sql .= ' WHERE connection_id = :c';
            $params['c'] = $connectionId;
        }
        $stmt = Database::connection()->prepare($sql);
        $stmt->execute($params);
        return (int) $stmt->fetchColumn();
    }

    public function countCredentials(?int $connectionId = null, ?string $status = null): int
    {
        $sql = 'SELECT COUNT(*) FROM unifi_credentials';
        $where = [];
        $params = [];
        if ($connectionId !== null) {
            $where[] = 'connection_id = :c';
            $params['c'] = $connectionId;
        }
        if ($status !== null) {
            $where[] = 'status = :s';
            $params['s'] = $status;
        }
        if ($where) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }
        $stmt = Database::connection()->prepare($sql);
        $stmt->execute($params);
        return (int) $stmt->fetchColumn();
    }

    public function countFreeCredentials(?int $connectionId = null): int
    {
        $sql = 'SELECT COUNT(*) FROM unifi_credentials WHERE (user_unifi_id IS NULL OR user_unifi_id = "")';
        $params = [];
        if ($connectionId !== null) {
            $sql .= ' AND connection_id = :c';
            $params['c'] = $connectionId;
        }
        $stmt = Database::connection()->prepare($sql);
        $stmt->execute($params);
        return (int) $stmt->fetchColumn();
    }

    public function countAccessGroups(?int $connectionId = null): int
    {
        $sql = 'SELECT COUNT(*) FROM unifi_access_groups';
        $params = [];
        if ($connectionId !== null) {
            $sql .= ' WHERE connection_id = :c';
            $params['c'] = $connectionId;
        }
        $stmt = Database::connection()->prepare($sql);
        $stmt->execute($params);
        return (int) $stmt->fetchColumn();
    }

    public function countDoors(?int $connectionId = null): int
    {
        $sql = 'SELECT COUNT(*) FROM unifi_doors';
        $params = [];
        if ($connectionId !== null) {
            $sql .= ' WHERE connection_id = :c';
            $params['c'] = $connectionId;
        }
        $stmt = Database::connection()->prepare($sql);
        $stmt->execute($params);
        return (int) $stmt->fetchColumn();
    }

    /**
     * Replace the cached state of one connection with a fresh UniFi snapshot
     * in a single transaction: upsert every fetched row, then delete only the
     * rows that no longer exist in UniFi. Readers never see a half-filled
     * cache, a failure leaves the previous state untouched, and the local
     * AD columns of unifi_users (ad_*) survive because upserts do not touch
     * them.
     *
     * @param array<int,array<string,mixed>> $users
     * @param array<int,array<string,mixed>> $credentials
     * @param array<int,array<string,mixed>> $groups
     * @param array<int,array<string,mixed>> $doors
     * @return array<string,int> number of deleted (stale) rows per table
     */
    public function replaceConnection(int $connectionId, array $users, array $credentials, array $groups, array $doors): array
    {
        return Database::transaction(function () use ($connectionId, $users, $credentials, $groups, $doors): array {
            $keep = ['users' => [], 'credentials' => [], 'groups' => [], 'doors' => []];

            foreach ($users as $user) {
                $this->upsertUser($connectionId, $user);
                $keep['users'][] = (string) ($user['id'] ?? '');
            }
            foreach ($credentials as $credential) {
                $this->upsertCredential($connectionId, $credential);
                $keep['credentials'][] = (string) ($credential['token'] ?? '');
            }
            foreach ($groups as $group) {
                $this->upsertAccessGroup($connectionId, $group);
                $keep['groups'][] = (string) ($group['id'] ?? '');
            }
            foreach ($doors as $door) {
                $this->upsertDoor($connectionId, $door);
                $keep['doors'][] = (string) ($door['id'] ?? '');
            }

            return [
                'users' => $this->deleteStale('unifi_users', 'unifi_id', $connectionId, $keep['users']),
                'credentials' => $this->deleteStale('unifi_credentials', 'unifi_token', $connectionId, $keep['credentials']),
                'access_groups' => $this->deleteStale('unifi_access_groups', 'unifi_id', $connectionId, $keep['groups']),
                'doors' => $this->deleteStale('unifi_doors', 'unifi_id', $connectionId, $keep['doors']),
            ];
        });
    }

    /** @param array<int,string> $keep */
    private function deleteStale(string $table, string $keyColumn, int $connectionId, array $keep): int
    {
        $pdo = Database::connection();
        $stmt = $pdo->prepare("SELECT {$keyColumn} FROM {$table} WHERE connection_id = :c");
        $stmt->execute(['c' => $connectionId]);
        $keepSet = array_flip($keep);

        $delete = $pdo->prepare("DELETE FROM {$table} WHERE connection_id = :c AND {$keyColumn} = :k");
        $deleted = 0;
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $key) {
            if (!isset($keepSet[(string) $key])) {
                $delete->execute(['c' => $connectionId, 'k' => (string) $key]);
                $deleted++;
            }
        }

        return $deleted;
    }

    /**
     * Cached persons of one connection including the local AD columns, as
     * needed by the AD sync for matching and offboarding.
     *
     * @return array<int,array<string,mixed>>
     */
    public function personsForAdSync(int $connectionId): array
    {
        $stmt = Database::connection()->prepare(
            'SELECT unifi_id, full_name, email, employee_number, status, access_policy_ids_json,
                    ad_identifier, ad_deactivated_at
             FROM unifi_users WHERE connection_id = :c'
        );
        $stmt->execute(['c' => $connectionId]);
        return $stmt->fetchAll();
    }

    /** @return array<string,mixed>|null */
    public function personForAdSync(int $connectionId, string $unifiId): ?array
    {
        $stmt = Database::connection()->prepare(
            'SELECT unifi_id, full_name, email, employee_number, status, access_policy_ids_json,
                    ad_identifier, ad_deactivated_at
             FROM unifi_users WHERE connection_id = :c AND unifi_id = :u'
        );
        $stmt->execute(['c' => $connectionId, 'u' => $unifiId]);
        return $stmt->fetch() ?: null;
    }

    /**
     * Mark (or unmark) a person as deactivated by the AD sync. Only persons
     * deactivated by the AD sync are re-activated automatically when their
     * AD account returns.
     */
    public function setAdDeactivated(int $connectionId, string $unifiId, bool $deactivated): void
    {
        Database::connection()->prepare(
            'UPDATE unifi_users SET ad_deactivated_at = ' . ($deactivated ? 'NOW()' : 'NULL') . '
             WHERE connection_id = :c AND unifi_id = :u'
        )->execute(['c' => $connectionId, 'u' => $unifiId]);
    }

    private function userParams(int $connectionId, array $user): array
    {
        $first = $user['first_name'] ?? '';
        $last = $user['last_name'] ?? '';
        $full = $user['full_name'] ?? trim($first . ' ' . $last);

        return [
            'c' => $connectionId,
            'uid' => (string) ($user['id'] ?? ''),
            'fn' => $first !== '' ? $first : null,
            'ln' => $last !== '' ? $last : null,
            'full' => $full !== '' ? $full : null,
            'email' => $user['user_email'] ?? $user['email'] ?? null,
            'emp' => $user['employee_number'] ?? null,
            'status' => $user['status'] ?? null,
            'onboard' => isset($user['onboard_time']) ? (int) $user['onboard_time'] : null,
            'policies' => json_encode($user['access_policy_ids'] ?? [], JSON_UNESCAPED_UNICODE),
            'raw' => json_encode($user, JSON_UNESCAPED_UNICODE),
        ];
    }
}
