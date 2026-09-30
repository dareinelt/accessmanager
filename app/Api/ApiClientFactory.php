<?php

declare(strict_types=1);

namespace App\Api;

use App\Config\Config;
use App\Security\Crypto;

/**
 * Builds the correct UniFi API client for a given connection row.
 * In mock mode every connection is backed by the MockUniFiApiClient.
 */
final class ApiClientFactory
{
    public static function forConnection(array $connection): UniFiApiClientInterface
    {
        $id = (int) $connection['id'];

        if (Config::isMock()) {
            $root = dirname(__DIR__, 2);
            $stateFile = $root . '/storage/cache/mock-' . $id . '.json';
            return new MockUniFiApiClient($stateFile, $id);
        }

        $token = Crypto::decrypt((string) $connection['api_token_enc']);

        return new UniFiApiClient(
            host: (string) $connection['host'],
            port: (int) $connection['port'],
            token: $token,
            verifySsl: (bool) $connection['verify_ssl'],
        );
    }
}
