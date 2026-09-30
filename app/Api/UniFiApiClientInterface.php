<?php

declare(strict_types=1);

namespace App\Api;

/**
 * Contract implemented by both the real UniFi Access API client and the
 * development/mock client. Business logic depends on this interface only,
 * which keeps the HTTP layer fully swappable and testable.
 */
interface UniFiApiClientInterface
{
    /** @return array<string,mixed> list of users (Personen) */
    public function getUsers(array $params = []): array;

    /** @return array<string,mixed>|null a single user or null when not found */
    public function getUser(string $id): ?array;

    public function createUser(array $data): array;

    public function updateUser(string $id, array $data): array;

    public function deleteUser(string $id): void;

    /** @return array<string,mixed> NFC cards / credentials */
    public function getCredentials(array $params = []): array;

    /** @return array<string,mixed>|null a single credential or null when not found */
    public function getCredential(string $token): ?array;

    public function assignCredential(string $userId, string $token): void;

    public function unassignCredential(string $userId, string $token): void;

    /** @return array<string,mixed> access policies (Zutrittsgruppen) */
    public function getAccessGroups(array $params = []): array;

    /** @return array<string,mixed>|null a single access group or null */
    public function getAccessGroup(string $id): ?array;

    public function createAccessGroup(array $data): array;

    public function updateAccessGroup(string $id, array $data): array;

    public function deleteAccessGroup(string $id): void;

    /** @return array<string,mixed> Access policies assigned to a user. */
    public function getUserAccessPolicies(string $userId): array;

    /** Replace the full set of access policies assigned to a user. */
    public function setUserAccessPolicies(string $userId, array $policyIds): void;

    /** @return array<string,mixed> Doors (Türen). */
    public function getDoors(): array;

    /** @return array<string,mixed>|null A single door or null. */
    public function getDoor(string $id): ?array;

    public function unlockDoor(string $id): void;
}
