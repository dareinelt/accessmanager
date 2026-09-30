<?php

declare(strict_types=1);

namespace App\Services;

use App\Api\ApiClientFactory;
use App\Api\UniFiApiException;
use App\Repositories\CatalogRepository;
use App\Repositories\ConnectionRepository;

/**
 * Door business logic: listing and remote unlock.
 */
final class DoorService
{
    public function __construct(
        private readonly CatalogRepository $catalog,
        private readonly ConnectionRepository $connections,
        private readonly AuditService $audit,
    ) {
    }

    /** @return array<int,array<string,mixed>> */
    public function list(?int $connectionId = null): array
    {
        return $this->catalog->listDoors($connectionId);
    }

    /** @return array<string,mixed> */
    public function get(int $connectionId, string $unifiId): array
    {
        foreach ($this->catalog->listDoors($connectionId) as $door) {
            if ($door['unifi_id'] === $unifiId) {
                return $door;
            }
        }
        throw new UniFiApiException('Tür wurde nicht gefunden.');
    }

    public function unlock(int $connectionId, string $unifiId, ?int $userId = null, ?string $username = null): void
    {
        $connection = $this->connections->find($connectionId);
        if ($connection === null) {
            throw new UniFiApiException('Standort wurde nicht gefunden.');
        }
        ApiClientFactory::forConnection($connection)->unlockDoor($unifiId);
        $this->audit->log('door.unlock', 'door', $unifiId, $unifiId, connectionId: $connectionId, userId: $userId, username: $username);
    }
}
