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
        $clean = $this->normalize($data, true);
        try {
            $id = $this->connections->create(
                name: $clean['name'],
                host: $clean['host'],
                port: $clean['port'] ?? 12445,
                token: $clean['api_token'],
                verifySsl: $clean['verify_ssl'] ?? false,
            );
        } catch (\PDOException $exception) {
            $this->rethrowDuplicate($exception);
        }
        $this->audit->log('site.create', 'site', (string) $id, $clean['name'], userId: $userId, username: $username);

        return $this->get($id);
    }

    /** @return array<string,mixed> */
    public function update(int $id, array $data, ?int $userId = null, ?string $username = null): array
    {
        $connection = $this->connections->find($id);
        if ($connection === null) {
            throw new UniFiApiException('Standort wurde nicht gefunden.');
        }
        // FIX: update() previously skipped validation (empty name/host,
        // non-numeric port, raw booleans -> SQL error) and a duplicate name
        // ended in HTTP 500.
        $clean = $this->normalize($data, false);
        try {
            $this->connections->update($id, $clean);
        } catch (\PDOException $exception) {
            $this->rethrowDuplicate($exception);
        }
        $this->audit->log('site.update', 'site', (string) $id, $clean['name'] ?? $connection['name'], userId: $userId, username: $username);

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

    /**
     * Validate and normalise connection input. On update ($requireAll=false)
     * only the supplied fields are checked.
     *
     * @return array<string,mixed>
     */
    private function normalize(array $data, bool $requireAll): array
    {
        $clean = [];
        if ($requireAll || array_key_exists('name', $data)) {
            $name = is_scalar($data['name'] ?? null) ? trim((string) $data['name']) : '';
            if ($name === '' || mb_strlen($name) > 128) {
                throw new UniFiApiException('Bitte einen Namen für den Standort angeben (max. 128 Zeichen).');
            }
            $clean['name'] = $name;
        }
        if ($requireAll || array_key_exists('host', $data)) {
            $host = is_scalar($data['host'] ?? null) ? trim((string) $data['host']) : '';
            // Host or IP only – no scheme, path or whitespace.
            if ($host === '' || strlen($host) > 255 || preg_match('/^[A-Za-z0-9.\-:\[\]]+$/', $host) !== 1) {
                throw new UniFiApiException('Bitte den Hostnamen bzw. die IP-Adresse des Controllers angeben (ohne https:// und Pfad).');
            }
            $clean['host'] = $host;
        }
        if ($requireAll || array_key_exists('port', $data)) {
            $port = filter_var($data['port'] ?? 12445, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 65535]]);
            if ($port === false) {
                throw new UniFiApiException('Der Port muss zwischen 1 und 65535 liegen.');
            }
            $clean['port'] = $port;
        }
        $token = is_scalar($data['api_token'] ?? null) ? trim((string) $data['api_token']) : '';
        if ($requireAll && $token === '') {
            throw new UniFiApiException('Bitte einen UniFi-API-Token angeben.');
        }
        if ($token !== '') {
            $clean['api_token'] = $token;
        }
        foreach (['verify_ssl', 'is_active'] as $flag) {
            if (array_key_exists($flag, $data)) {
                $clean[$flag] = filter_var($data[$flag], FILTER_VALIDATE_BOOLEAN);
            }
        }
        if (array_key_exists('is_active', $clean)) {
            $clean['is_active'] = $clean['is_active'] ? 1 : 0;
        }

        return $clean;
    }

    private function rethrowDuplicate(\PDOException $exception): never
    {
        if ($exception->getCode() === '23000') {
            throw new UniFiApiException('Ein Standort mit diesem Namen existiert bereits.');
        }
        throw $exception;
    }
}
