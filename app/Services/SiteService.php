<?php

declare(strict_types=1);

namespace App\Services;

use App\Api\ApiClientFactory;
use App\Api\UniFiApiException;
use App\Repositories\CatalogRepository;
use App\Repositories\ConnectionRepository;

/**
 * Site/location business logic. In the UniFi Access model one controller
 * equals one location ("Standort"); there is no separate sites endpoint.
 */
final class SiteService
{
    public function __construct(
        private readonly ConnectionRepository $connections,
        private readonly CatalogRepository $catalog,
        private readonly AuditService $audit,
    ) {
    }

    /** @return array<int,array<string,mixed>> */
    public function list(): array
    {
        $stats = $this->catalog->locationStats();
        $map = [];
        foreach ($stats as $row) {
            $map[(int) $row['id']] = $row;
        }

        $out = [];
        foreach ($this->connections->all() as $c) {
            $id = (int) $c['id'];
            $s = $map[$id] ?? ['id' => $id, 'name' => $c['name'], 'users' => 0, 'credentials' => 0, 'doors' => 0, 'groups' => 0];
            $out[] = array_merge($s, [
                'host' => (string) $c['host'],
                'port' => (int) $c['port'],
                'verify_ssl' => (bool) $c['verify_ssl'],
                'is_active' => (bool) $c['is_active'],
            ]);
        }

        return $out;
    }

    /** @return array<string,mixed> */
    public function get(int $id): array
    {
        $connection = $this->connections->find($id);
        if ($connection === null) {
            throw new UniFiApiException('Standort wurde nicht gefunden.');
        }
        $stats = null;
        foreach ($this->catalog->locationStats() as $row) {
            if ((int) $row['id'] === $id) {
                $stats = $row;
                break;
            }
        }
        return ['connection' => $connection, 'stats' => $stats];
    }

    /** @return array<string,mixed> */
    public function create(array $data, ?int $userId = null, ?string $username = null): array
    {
        $this->validate($data);
        $id = $this->connections->create(
            name: trim((string) $data['name']),
            host: trim((string) $data['host']),
            port: (int) ($data['port'] ?? 12445),
            token: trim((string) $data['api_token']),
            verifySsl: (bool) ($data['verify_ssl'] ?? false),
        );
        $this->audit->log('site.create', 'site', (string) $id, trim((string) $data['name']), userId: $userId, username: $username);

        return $this->get($id);
    }

    /** @return array<string,mixed> */
    public function update(int $id, array $data, ?int $userId = null, ?string $username = null): array
    {
        $connection = $this->connections->find($id);
        if ($connection === null) {
            throw new UniFiApiException('Standort wurde nicht gefunden.');
        }
        if (array_key_exists('port', $data) && ($data['port'] < 1 || $data['port'] > 65535)) {
            throw new UniFiApiException('Der Port muss zwischen 1 und 65535 liegen.');
        }
        $this->connections->update($id, $data);
        $this->audit->log('site.update', 'site', (string) $id, $connection['name'], userId: $userId, username: $username);

        return $this->get($id);
    }

    public function delete(int $id, ?int $userId = null, ?string $username = null): void
    {
        $connection = $this->connections->find($id);
        if ($connection === null) {
            throw new UniFiApiException('Standort wurde nicht gefunden.');
        }
        $this->connections->delete($id);
        $this->audit->log('site.delete', 'site', (string) $id, $connection['name'], userId: $userId, username: $username);
    }

    /** @return array<string,mixed> */
    public function test(int $id): array
    {
        $connection = $this->connections->find($id);
        if ($connection === null) {
            throw new UniFiApiException('Standort wurde nicht gefunden.');
        }
        try {
            $client = ApiClientFactory::forConnection($connection);
            $doors = $client->getDoors();
            return ['success' => true, 'doors' => count($doors['data'] ?? [])];
        } catch (UniFiApiException $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        } catch (\Throwable $e) {
            return ['success' => false, 'error' => 'Verbindungstest fehlgeschlagen: ' . $e->getMessage()];
        }
    }

    private function validate(array $data): void
    {
        if (empty(trim((string) ($data['name'] ?? '')))) {
            throw new UniFiApiException('Bitte einen Namen für den Standort angeben.');
        }
        if (empty(trim((string) ($data['host'] ?? '')))) {
            throw new UniFiApiException('Bitte den Hostnamen bzw. die IP-Adresse des Controllers angeben.');
        }
        $port = (int) ($data['port'] ?? 12445);
        if ($port < 1 || $port > 65535) {
            throw new UniFiApiException('Der Port muss zwischen 1 und 65535 liegen.');
        }
        if (empty(trim((string) ($data['api_token'] ?? '')))) {
            throw new UniFiApiException('Bitte einen UniFi-API-Token angeben.');
        }
    }
}
