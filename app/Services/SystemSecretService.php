<?php

declare(strict_types=1);

namespace App\Services;

use App\Repositories\SystemSecretRepository;
use App\Security\Crypto;

/**
 * Management of sensitive system configuration (Active Directory data, DNs,
 * UniFi API endpoints, ...). Only the sysadmin role may read or mutate these
 * values; all access is audited and the values never leave the database
 * unencrypted except when explicitly revealed to a sysadmin.
 */
final class SystemSecretService
{
    public const CATEGORIES = [
        'ad' => 'Active Directory',
        'ldap' => 'LDAP / DN',
        'unifi' => 'UniFi API',
        'other' => 'Sonstiges',
    ];

    public function __construct(
        private readonly SystemSecretRepository $secrets,
        private readonly AuditService $audit,
    ) {
    }

    /** @return array<int,array<string,mixed>> */
    public function list(): array
    {
        return $this->secrets->all();
    }

    public function reveal(int $id, ?int $actorId = null, ?string $actorName = null): string
    {
        $secret = $this->require($id);
        $value = Crypto::decrypt((string) $secret['value_enc']);
        $this->audit->log('system_secret.reveal', 'system_secret', (string) $id, (string) $secret['key'], userId: $actorId, username: $actorName);
        return $value;
    }

    /** @return array<string,mixed> */
    public function create(array $data, ?int $actorId = null, ?string $actorName = null): array
    {
        $key = $this->normalizeKey((string) ($data['key'] ?? ''));
        $label = trim((string) ($data['label'] ?? ''));
        $category = (string) ($data['category'] ?? 'other');
        $value = (string) ($data['value'] ?? '');

        if ($key === '') {
            throw new \InvalidArgumentException('Bitte einen Schlüssel angeben.');
        }
        if ($label === '') {
            throw new \InvalidArgumentException('Bitte eine Bezeichnung angeben.');
        }
        if ($value === '') {
            throw new \InvalidArgumentException('Bitte einen Wert angeben.');
        }
        if (!array_key_exists($category, self::CATEGORIES)) {
            throw new \InvalidArgumentException('Ungültige Kategorie.');
        }
        if ($this->secrets->findByKey($key) !== null) {
            throw new \InvalidArgumentException('Dieser Schlüssel ist bereits vergeben.');
        }

        $id = $this->secrets->create($key, $label, $category, $value);
        $this->audit->log('system_secret.create', 'system_secret', (string) $id, $key, userId: $actorId, username: $actorName);

        return ['id' => $id, 'key' => $key];
    }

    /** @return array<string,mixed> */
    public function update(int $id, array $data, ?int $actorId = null, ?string $actorName = null): array
    {
        $secret = $this->require($id);

        $payload = [];
        if (array_key_exists('label', $data)) {
            $label = trim((string) $data['label']);
            if ($label === '') {
                throw new \InvalidArgumentException('Bitte eine Bezeichnung angeben.');
            }
            $payload['label'] = $label;
        }
        if (array_key_exists('category', $data)) {
            $category = (string) $data['category'];
            if (!array_key_exists($category, self::CATEGORIES)) {
                throw new \InvalidArgumentException('Ungültige Kategorie.');
            }
            $payload['category'] = $category;
        }
        if (array_key_exists('value', $data) && (string) $data['value'] !== '') {
            $payload['value'] = (string) $data['value'];
        }

        if ($payload !== []) {
            $this->secrets->update($id, $payload);
            $this->audit->log('system_secret.update', 'system_secret', (string) $id, (string) $secret['key'], userId: $actorId, username: $actorName);
        }

        return ['id' => $id];
    }

    public function delete(int $id, ?int $actorId = null, ?string $actorName = null): void
    {
        $secret = $this->require($id);
        $this->secrets->delete($id);
        $this->audit->log('system_secret.delete', 'system_secret', (string) $id, (string) $secret['key'], userId: $actorId, username: $actorName);
    }

    /** @return array<string,mixed> */
    private function require(int $id): array
    {
        $secret = $this->secrets->find($id);
        if ($secret === null) {
            throw new \InvalidArgumentException('Eintrag wurde nicht gefunden.');
        }
        return $secret;
    }

    private function normalizeKey(string $key): string
    {
        $key = trim($key);
        if ($key !== '' && !preg_match('/^[a-zA-Z0-9._-]+$/', $key)) {
            throw new \InvalidArgumentException('Der Schlüssel darf nur Buchstaben, Ziffern, Punkte, Unterstriche und Bindestriche enthalten.');
        }
        return $key;
    }
}
