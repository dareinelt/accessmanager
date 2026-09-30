<?php

declare(strict_types=1);

namespace App\Services;

use App\Api\ApiClientFactory;
use App\Api\UniFiApiException;
use App\Repositories\CacheRepository;
use App\Repositories\CatalogRepository;
use App\Repositories\ConnectionRepository;

/**
 * Access-group (UniFi "access policy") business logic.
 */
final class AccessGroupService
{
    public function __construct(
        private readonly CatalogRepository $catalog,
        private readonly CacheRepository $cache,
        private readonly ConnectionRepository $connections,
        private readonly AuditService $audit,
    ) {
    }

    /** @return array<int,array<string,mixed>> */
    public function list(?int $connectionId = null): array
    {
        $groups = $this->catalog->listAccessGroups($connectionId);
        $memberCounts = array_count_values($this->catalog->listAllUserPolicyIds($connectionId));
        $doorNames = $this->doorNameMap($connectionId);

        foreach ($groups as &$group) {
            $group['member_count'] = $memberCounts[$group['unifi_id']] ?? 0;
            $group['door_names'] = $this->resolveDoorNames($group['resources_json'] ?? '[]', $doorNames);
            $group['resources'] = json_decode($group['resources_json'] ?? '[]', true) ?: [];
        }
        unset($group);

        return $groups;
    }

    /** @return array<string,mixed> */
    public function get(int $connectionId, string $unifiId): array
    {
        $group = $this->catalog->getAccessGroup($connectionId, $unifiId);
        if ($group === null) {
            throw new UniFiApiException('Zutrittsgruppe wurde nicht gefunden.');
        }
        $group['resources'] = json_decode($group['resources_json'] ?? '[]', true) ?: [];
        $group['member_count'] = count(array_filter(
            $this->catalog->listAllUserPolicyIds($connectionId),
            fn ($id) => $id === $unifiId
        ));
        $group['door_names'] = $this->resolveDoorNames($group['resources_json'] ?? '[]', $this->doorNameMap($connectionId));

        return $group;
    }

    /** @return array<string,mixed> */
    public function create(int $connectionId, array $data, ?int $userId = null, ?string $username = null): array
    {
        $client = $this->client($connectionId);
        $created = $client->createAccessGroup($this->groupPayload($data));
        $unifiId = (string) ($created['id'] ?? '');
        if ($unifiId === '') {
            throw new UniFiApiException('UniFi hat keine gültige Gruppen-ID zurückgegeben.');
        }
        $this->cache->upsertAccessGroup($connectionId, $created);
        $this->audit->log('group.create', 'access_group', $unifiId, $created['name'] ?? $unifiId, connectionId: $connectionId, userId: $userId, username: $username);

        return $created;
    }

    /** @return array<string,mixed> */
    public function update(int $connectionId, string $unifiId, array $data, ?int $userId = null, ?string $username = null): array
    {
        $client = $this->client($connectionId);
        $current = $client->getAccessGroup($unifiId) ?? [];
        $merged = array_merge($current, $this->groupPayload($data));
        $updated = $client->updateAccessGroup($unifiId, $merged);
        $this->cache->upsertAccessGroup($connectionId, $updated);
        $this->audit->log('group.update', 'access_group', $unifiId, $merged['name'] ?? $unifiId, connectionId: $connectionId, userId: $userId, username: $username);

        return $updated;
    }

    public function delete(int $connectionId, string $unifiId, ?int $userId = null, ?string $username = null): void
    {
        $client = $this->client($connectionId);
        $client->deleteAccessGroup($unifiId);
        $this->cache->deleteAccessGroup($connectionId, $unifiId);
        $this->audit->log('group.delete', 'access_group', $unifiId, $unifiId, connectionId: $connectionId, userId: $userId, username: $username);
    }

    // ------------------------------------------------------------- helpers

    private function client(int $connectionId): \App\Api\UniFiApiClientInterface
    {
        $connection = $this->connections->find($connectionId);
        if ($connection === null) {
            throw new UniFiApiException('Standort wurde nicht gefunden.');
        }
        return ApiClientFactory::forConnection($connection);
    }

    /** @return array<string,mixed> */
    private function groupPayload(array $data): array
    {
        $payload = [];
        if (array_key_exists('name', $data)) {
            $payload['name'] = (string) $data['name'];
        }
        if (array_key_exists('schedule_id', $data)) {
            $payload['schedule_id'] = $data['schedule_id'] ?: null;
        }
        if (array_key_exists('resources', $data) && is_array($data['resources'])) {
            $payload['resources'] = $data['resources'];
        }
        return $payload;
    }

    /** @return array<string,string> */
    private function doorNameMap(?int $connectionId): array
    {
        $map = [];
        foreach ($this->catalog->listDoors($connectionId) as $door) {
            $map[$door['unifi_id']] = $door['name'];
        }
        return $map;
    }

    /** @return array<int,string> */
    private function resolveDoorNames(string $resourcesJson, array $doorNames): array
    {
        $resources = json_decode($resourcesJson, true) ?: [];
        $names = [];
        foreach ($resources as $resource) {
            $id = (string) ($resource['id'] ?? '');
            $type = (string) ($resource['type'] ?? '');
            if (isset($doorNames[$id])) {
                $names[] = $doorNames[$id] . ($type === 'door_group' ? ' (Gruppe)' : '');
            } elseif ($id !== '') {
                $names[] = $id;
            }
        }
        return $names;
    }
}
