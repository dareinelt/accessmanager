<?php

declare(strict_types=1);

namespace App\Api;

use App\Core\Logger;

/**
 * Real UniFi Access API client.
 *
 * Talks to a single UniFi Access controller over HTTPS (default port 12445)
 * using a scoped API token (Authorization: Bearer <token>). Only official,
 * documented endpoints are used — see /docs/unifi-api.md for the full list.
 */
final class UniFiApiClient implements UniFiApiClientInterface
{
    private string $baseUrl;
    private int $maxRetries;

    public function __construct(
        private readonly string $host,
        private readonly int $port,
        private readonly string $token,
        private readonly bool $verifySsl = false,
    ) {
        $scheme = $this->verifySsl ? 'https' : 'https';
        $this->baseUrl = rtrim("{$scheme}://{$this->host}:{$this->port}", '/');
        $this->maxRetries = 2;
    }

    // ---------------------------------------------------------------- Users

    public function getUsers(array $params = []): array
    {
        $query = array_merge(['expand[]' => 'access_policy'], $params);
        $res = $this->request('GET', '/api/v1/developer/users', null, $query);

        return [
            'data' => $res['data'] ?? [],
            'pagination' => $res['pagination'] ?? null,
        ];
    }

    public function getUser(string $id): ?array
    {
        $res = $this->request('GET', '/api/v1/developer/users/' . rawurlencode($id), null, ['expand[]' => 'access_policy']);
        $data = $res['data'] ?? null;

        return is_array($data) ? $data : null;
    }

    public function createUser(array $data): array
    {
        $res = $this->request('POST', '/api/v1/developer/users', $data);

        return is_array($res['data'] ?? null) ? $res['data'] : [];
    }

    public function updateUser(string $id, array $data): array
    {
        $res = $this->request('PUT', '/api/v1/developer/users/' . rawurlencode($id), $data);

        return is_array($res['data'] ?? null) ? $res['data'] : [];
    }

    public function deleteUser(string $id): void
    {
        $this->request('DELETE', '/api/v1/developer/users/' . rawurlencode($id));
    }

    public function getUserAccessPolicies(string $userId): array
    {
        $res = $this->request('GET', '/api/v1/developer/users/' . rawurlencode($userId) . '/access_policies');

        return $res['data'] ?? [];
    }

    public function setUserAccessPolicies(string $userId, array $policyIds): void
    {
        $this->request('PUT', '/api/v1/developer/users/' . rawurlencode($userId) . '/access_policies', [
            'access_policy_ids' => array_values($policyIds),
        ]);
    }

    // ---------------------------------------------------------- Credentials

    public function getCredentials(array $params = []): array
    {
        $res = $this->request('GET', '/api/v1/developer/credentials/nfc_cards/tokens', null, $params);

        return [
            'data' => $res['data'] ?? [],
            'pagination' => $res['pagination'] ?? null,
        ];
    }

    public function getCredential(string $token): ?array
    {
        $res = $this->request('GET', '/api/v1/developer/credentials/nfc_cards/tokens/' . rawurlencode($token));
        $data = $res['data'] ?? null;

        return is_array($data) ? $data : null;
    }

    public function assignCredential(string $userId, string $token): void
    {
        $this->request('PUT', '/api/v1/developer/users/' . rawurlencode($userId) . '/nfc_cards', [
            'token' => $token,
            'force_add' => false,
        ]);
    }

    public function unassignCredential(string $userId, string $token): void
    {
        $this->request('PUT', '/api/v1/developer/users/' . rawurlencode($userId) . '/nfc_cards/delete', [
            'token' => $token,
        ]);
    }

    // ------------------------------------------------------- Access policies

    public function getAccessGroups(array $params = []): array
    {
        $res = $this->request('GET', '/api/v1/developer/access_policies', null, $params);

        return [
            'data' => $res['data'] ?? [],
            'pagination' => $res['pagination'] ?? null,
        ];
    }

    public function getAccessGroup(string $id): ?array
    {
        $res = $this->request('GET', '/api/v1/developer/access_policies/' . rawurlencode($id));
        $data = $res['data'] ?? null;

        return is_array($data) ? $data : null;
    }

    public function createAccessGroup(array $data): array
    {
        $res = $this->request('POST', '/api/v1/developer/access_policies', $data);

        return is_array($res['data'] ?? null) ? $res['data'] : [];
    }

    public function updateAccessGroup(string $id, array $data): array
    {
        $res = $this->request('PUT', '/api/v1/developer/access_policies/' . rawurlencode($id), $data);

        return is_array($res['data'] ?? null) ? $res['data'] : [];
    }

    public function deleteAccessGroup(string $id): void
    {
        $this->request('DELETE', '/api/v1/developer/access_policies/' . rawurlencode($id));
    }

    // ----------------------------------------------------------------- Doors

    public function getDoors(): array
    {
        $res = $this->request('GET', '/api/v1/developer/doors');

        return [
            'data' => $res['data'] ?? [],
            'pagination' => null,
        ];
    }

    public function getDoor(string $id): ?array
    {
        $res = $this->request('GET', '/api/v1/developer/doors/' . rawurlencode($id));
        $data = $res['data'] ?? null;

        return is_array($data) ? $data : null;
    }

    public function unlockDoor(string $id): void
    {
        $this->request('PUT', '/api/v1/developer/doors/' . rawurlencode($id) . '/unlock', [
            'actor_name' => 'UniFi Access Manager',
        ]);
    }

    // ------------------------------------------------------------ HTTP core

    /**
     * Perform an HTTP request with retry-on-network-error and consistent
     * error translation. Never logs the token.
     */
    private function request(string $method, string $path, ?array $body = null, array $query = []): array
    {
        $url = $this->baseUrl . $path;
        if ($query) {
            $url .= '?' . http_build_query($query);
        }

        $attempt = 0;
        while (true) {
            $attempt++;
            $ch = curl_init($url);

            $options = [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HTTPHEADER => [
                    'Accept: application/json',
                    'Content-Type: application/json',
                    'Authorization: Bearer ' . $this->token,
                ],
                CURLOPT_CONNECTTIMEOUT => 10,
                CURLOPT_TIMEOUT => 30,
                CURLOPT_SSL_VERIFYPEER => $this->verifySsl,
                CURLOPT_SSL_VERIFYHOST => $this->verifySsl ? 2 : 0,
            ];

            if ($method !== 'GET') {
                $options[CURLOPT_CUSTOMREQUEST] = $method;
                if ($body !== null) {
                    $options[CURLOPT_POSTFIELDS] = (string) json_encode($body);
                }
            }

            curl_setopt_array($ch, $options);
            $response = curl_exec($ch);
            $errno = curl_errno($ch);
            $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $requestSize = (int) curl_getinfo($ch, CURLINFO_REQUEST_SIZE);
            curl_close($ch);

            if ($response === false) {
                Logger::warning('unifi', "Network error (attempt {$attempt}): {$errno} for {$method} {$path}");
                if (self::mayRetry($method, $errno, $requestSize) && $attempt <= $this->maxRetries) {
                    usleep(250000 * $attempt);
                    continue;
                }
                if (!self::isIdempotent($method) && !self::requestNotSent($errno, $requestSize)) {
                    throw new UniFiApiException(
                        'Die Verbindung zu UniFi wurde während der Anfrage unterbrochen. Es ist unklar, ob die Änderung ausgeführt wurde. '
                        . 'Bitte synchronisieren Sie den Standort und prüfen Sie den Datenbestand, bevor Sie es erneut versuchen.',
                        0,
                        null,
                        true,
                    );
                }
                throw new UniFiApiException('Die Verbindung zu UniFi konnte nicht hergestellt werden. Bitte überprüfen Sie Host und Erreichbarkeit.');
            }

            $decoded = json_decode((string) $response, true);

            if ($httpCode >= 400) {
                Logger::warning('unifi', "HTTP {$httpCode} for {$method} {$path}");
                throw new UniFiApiException(
                    $this->friendlyError($httpCode, is_array($decoded) ? $decoded : null),
                    $httpCode,
                    is_array($decoded) ? $decoded : null,
                );
            }

            if (!is_array($decoded)) {
                throw new UniFiApiException('Unerwartete Antwort von UniFi.', $httpCode);
            }

            if (isset($decoded['code']) && strtoupper((string) $decoded['code']) !== 'SUCCESS') {
                $msg = (string) ($decoded['msg'] ?? $decoded['code'] ?? 'Unbekannter UniFi-Fehler');
                throw new UniFiApiException($msg, $httpCode, $decoded);
            }

            return $decoded;
        }
    }

    /**
     * Network errors are retried for idempotent methods only. POST (create)
     * is retried solely when the request provably never left this host,
     * otherwise a timeout after successful processing would create
     * duplicate persons/groups in UniFi.
     */
    public static function mayRetry(string $method, int $errno, int $requestSize): bool
    {
        return self::isIdempotent($method) || self::requestNotSent($errno, $requestSize);
    }

    private static function isIdempotent(string $method): bool
    {
        return in_array(strtoupper($method), ['GET', 'PUT', 'DELETE'], true);
    }

    private static function requestNotSent(int $errno, int $requestSize): bool
    {
        // 5/6 = proxy/host not resolvable, 7 = connection refused.
        return $requestSize === 0 || in_array($errno, [5, 6, 7], true);
    }

    private function friendlyError(int $httpCode, ?array $body): string
    {
        return match ($httpCode) {
            401 => 'UniFi-Authentifizierung fehlgeschlagen. Bitte prüfen Sie den API-Token.',
            403 => 'Der API-Token besitzt nicht die erforderlichen Berechtigungen.',
            404 => 'Das angeforderte Objekt wurde in UniFi nicht gefunden.',
            429 => 'Zu viele Anfragen an UniFi (Rate Limit). Bitte später erneut versuchen.',
            default => 'UniFi meldete einen Fehler (HTTP ' . $httpCode . ').',
        };
    }
}
