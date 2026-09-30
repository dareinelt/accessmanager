<?php

declare(strict_types=1);

namespace App\Services;

use App\Api\UniFiApiException;
use App\Repositories\CatalogRepository;

/**
 * Read-side service for UniFi credentials (NFC cards). Card assignment and
 * unassignment are person operations and live in PersonService.
 */
final class CredentialService
{
    public function __construct(private readonly CatalogRepository $catalog)
    {
    }

    /** @return array{items:array<int,array<string,mixed>>,total:int} */
    public function list(array $filters = []): array
    {
        return $this->catalog->listCredentials($filters);
    }

    /** @return array<string,mixed> */
    public function get(int $connectionId, string $token): array
    {
        $credential = $this->catalog->getCredential($connectionId, $token);
        if ($credential === null) {
            throw new UniFiApiException('Karte/Zugangsmedium wurde nicht gefunden.');
        }
        return $credential;
    }
}
