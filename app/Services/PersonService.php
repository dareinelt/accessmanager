<?php

declare(strict_types=1);

namespace App\Services;

use App\Api\ApiClientFactory;
use App\Api\UniFiApiException;
use App\Repositories\CacheRepository;
use App\Repositories\CatalogRepository;
use App\Repositories\ConnectionRepository;

/**
 * Person (UniFi user) business logic: reads from the local cache and
 * delegates every mutation to the UniFi API, then refreshes the cache.
 */
final class PersonService
{
    public function __construct(
        private readonly CatalogRepository $catalog,
        private readonly CacheRepository $cache,
        private readonly ConnectionRepository $connections,
        private readonly AuditService $audit,
    ) {
    }

    /** @return array{items:array<int,array<string,mixed>>,total:int} */
    public function list(array $filters = []): array
    {
        return $this->catalog->listPersons($filters);
    }

    /** @return array<string,mixed> */
    public function get(int $connectionId, string $unifiId): array
    {
        $person = $this->catalog->getPerson($connectionId, $unifiId);
        if ($person === null) {
            throw new UniFiApiException('Person wurde nicht gefunden.');
        }

        $credentials = $this->catalog->credentialsForPerson($connectionId, $unifiId);
        $policyIds = json_decode($person['access_policy_ids_json'] ?? '[]', true) ?: [];

        return [
            'person' => $person,
            'credentials' => $credentials,
            'access_policy_ids' => $policyIds,
            'access_policy_names' => $this->groupNameMap($connectionId, $policyIds),
            'raw' => json_decode($person['raw_json'] ?? '{}', true) ?: [],
        ];
    }

    /**
     * SECURITY FIX: strip door PIN codes and the raw controller payload from
     * person details for read-only users (previously visible to everyone).
     *
     * @param array<string,mixed> $detail
     * @return array<string,mixed>
     */
    public static function redactForReadonly(array $detail): array
    {
        unset($detail['person']['raw_json']);
        $detail['raw'] = [];
        $detail['redacted'] = true;
        return $detail;
    }

    /** @return array<string,mixed> */
    public function create(int $connectionId, array $data, ?int $userId = null, ?string $username = null): array
    {
        $client = $this->client($connectionId);
        $created = $client->createUser($this->userPayload($data));
        $unifiId = (string) ($created['id'] ?? '');
        if ($unifiId === '') {
            throw new UniFiApiException('UniFi hat keine gültige Benutzer-ID zurückgegeben.');
        }

        $this->refresh($connectionId, $client, $unifiId);
        $this->audit->log('person.create', 'person', $unifiId, $this->label($created), connectionId: $connectionId, userId: $userId, username: $username);

        return $created;
    }

    /** @return array<string,mixed> */
    public function update(int $connectionId, string $unifiId, array $data, ?int $userId = null, ?string $username = null): array
    {
        $client = $this->client($connectionId);
        $current = $client->getUser($unifiId) ?? [];
        $merged = array_merge($current, $this->userPayload($data));
        $updated = $client->updateUser($unifiId, $merged);

        $this->refresh($connectionId, $client, $unifiId);
        $this->audit->log('person.update', 'person', $unifiId, $this->label($merged), connectionId: $connectionId, userId: $userId, username: $username);

        return $updated;
    }

    public function delete(int $connectionId, string $unifiId, ?int $userId = null, ?string $username = null): void
    {
        $client = $this->client($connectionId);
        $client->deleteUser($unifiId);
        $this->cache->deleteUser($connectionId, $unifiId);
        $this->audit->log('person.delete', 'person', $unifiId, $unifiId, connectionId: $connectionId, userId: $userId, username: $username);
    }

    public function assignCard(int $connectionId, string $unifiId, string $token, ?int $userId = null, ?string $username = null): void
    {
        $client = $this->client($connectionId);
        $client->assignCredential($unifiId, $token);
        $this->refresh($connectionId, $client, $unifiId, $token);
        $this->audit->log('person.assign_card', 'person', $unifiId, $unifiId, ['token' => $token], $connectionId, $userId, $username);
    }

    public function unassignCard(int $connectionId, string $unifiId, string $token, ?int $userId = null, ?string $username = null): void
    {
        $client = $this->client($connectionId);
        $client->unassignCredential($unifiId, $token);
        $this->refresh($connectionId, $client, $unifiId, $token);
        $this->audit->log('person.unassign_card', 'person', $unifiId, $unifiId, ['token' => $token], $connectionId, $userId, $username);
    }

    /** @param array<int,string> $policyIds */
    public function setGroups(int $connectionId, string $unifiId, array $policyIds, ?int $userId = null, ?string $username = null): void
    {
        $client = $this->client($connectionId);
        $client->setUserAccessPolicies($unifiId, $policyIds);
        $this->refresh($connectionId, $client, $unifiId);
        $this->audit->log('person.set_groups', 'person', $unifiId, $unifiId, ['access_policy_ids' => $policyIds], $connectionId, $userId, $username);
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

    private function refresh(int $connectionId, \App\Api\UniFiApiClientInterface $client, string $unifiId, ?string $credToken = null): void
    {
        $user = $client->getUser($unifiId);
        if ($user !== null) {
            $this->cache->upsertUser($connectionId, $user);
        }
        if ($credToken !== null) {
            $cred = $client->getCredential($credToken);
            if ($cred !== null) {
                $this->cache->upsertCredential($connectionId, $cred);
            }
        }
    }

    /** @return array<string,mixed> */
    private function userPayload(array $data): array
    {
        $payload = [];
        $map = [
            'first_name' => 'first_name',
            'last_name' => 'last_name',
            'user_email' => 'user_email',
            'email' => 'user_email',
            'employee_number' => 'employee_number',
            'status' => 'status',
            'pin_code' => 'pin_code',
        ];
        foreach ($map as $in => $out) {
            if (array_key_exists($in, $data)) {
                $payload[$out] = $data[$in];
            }
        }
        if (array_key_exists('full_name', $data) && $data['full_name'] !== null && $data['full_name'] !== '') {
            $payload['full_name'] = $data['full_name'];
        }

        return $payload;
    }

    private function label(array $user): string
    {
        return trim((string) ($user['full_name'] ?? trim(($user['first_name'] ?? '') . ' ' . ($user['last_name'] ?? ''))));
    }

    /** @param array<int,string> $policyIds @return array<string,string> */
    private function groupNameMap(int $connectionId, array $policyIds): array
    {
        $map = [];
        foreach ($this->catalog->listAccessGroups($connectionId) as $group) {
            if (in_array($group['unifi_id'], $policyIds, true)) {
                $map[$group['unifi_id']] = $group['name'];
            }
        }
        return $map;
    }
}
