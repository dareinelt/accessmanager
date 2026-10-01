<?php

declare(strict_types=1);

namespace App\Core;

use App\Config\Config;

final class Request
{
    private readonly array $query;
    private readonly array $body;
    private readonly array $server;
    private readonly array $headers;

    public function __construct()
    {
        $this->query = $_GET;
        $this->server = $_SERVER;
        $this->headers = $this->parseHeaders();
        $this->body = $this->readBody();
    }

    private function parseHeaders(): array
    {
        $headers = [];
        foreach ($_SERVER as $key => $value) {
            if (str_starts_with((string) $key, 'HTTP_')) {
                $name = strtolower(str_replace('_', '-', substr((string) $key, 5)));
                $headers[$name] = (string) $value;
            }
        }
        if (isset($_SERVER['CONTENT_TYPE'])) {
            $headers['content-type'] = (string) $_SERVER['CONTENT_TYPE'];
        }
        return $headers;
    }

    private function readBody(): array
    {
        $contentType = $this->headers['content-type'] ?? '';
        // multipart bodies are already parsed by PHP and php://input is empty.
        if (str_contains($contentType, 'multipart/form-data')) {
            return $_POST;
        }
        $raw = file_get_contents('php://input');
        if ($raw === false || $raw === '') {
            return $_POST;
        }
        if (str_contains($contentType, 'application/json')) {
            $decoded = json_decode($raw, true);
            return is_array($decoded) ? $decoded : [];
        }
        parse_str($raw, $parsed);
        return array_merge($_POST, $parsed);
    }

    public function method(): string
    {
        return strtoupper((string) ($this->server['REQUEST_METHOD'] ?? 'GET'));
    }

    public function path(): string
    {
        $uri = (string) ($this->server['REQUEST_URI'] ?? '/');
        $path = parse_url($uri, PHP_URL_PATH) ?: '/';
        return '/' . ltrim((string) $path, '/');
    }

    public function query(string $key, mixed $default = null): mixed
    {
        return $this->query[$key] ?? $default;
    }

    /**
     * Optional positive integer from the query string. Empty strings
     * ("Alle Standorte") and non-numeric input yield null instead of 0.
     */
    public function queryInt(string $key): ?int
    {
        $value = filter_var($this->query[$key] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        return $value === false ? null : $value;
    }

    /** Optional scalar string from the query string (arrays are rejected). */
    public function queryString(string $key): ?string
    {
        $value = $this->query[$key] ?? null;
        if (!is_scalar($value)) {
            return null;
        }
        $value = trim((string) $value);
        return $value === '' ? null : $value;
    }

    public function input(string $key, mixed $default = null): mixed
    {
        return $this->body[$key] ?? $default;
    }

    public function all(): array
    {
        return $this->body;
    }

    public function header(string $name, mixed $default = null): mixed
    {
        return $this->headers[strtolower($name)] ?? $default;
    }

    public function ip(): string
    {
        return self::clientIp($this->server);
    }

    public function isJson(): bool
    {
        return str_contains($this->headers['content-type'] ?? '', 'application/json');
    }

    public function wantsJson(): bool
    {
        return str_contains($this->headers['accept'] ?? '', 'application/json') || $this->isJson();
    }

    /**
     * SECURITY FIX: X-Forwarded-For was trusted unconditionally, so any
     * client could forge the IP address written to the audit log and the
     * login rate limiter. It is now only honoured when the direct peer is
     * listed in TRUSTED_PROXIES (comma separated IPs, or "*" for any).
     */
    public static function clientIp(array $server): string
    {
        $remote = (string) ($server['REMOTE_ADDR'] ?? 'unknown');
        $fwd = (string) ($server['HTTP_X_FORWARDED_FOR'] ?? '');
        if ($fwd !== '' && self::isTrustedProxy($remote)) {
            $candidate = trim(explode(',', $fwd)[0]);
            if (filter_var($candidate, FILTER_VALIDATE_IP) !== false) {
                return $candidate;
            }
        }
        return $remote;
    }

    public static function isSecure(array $server): bool
    {
        $https = (string) ($server['HTTPS'] ?? '');
        if ($https !== '' && strtolower($https) !== 'off') {
            return true;
        }
        $proto = strtolower((string) ($server['HTTP_X_FORWARDED_PROTO'] ?? ''));
        return $proto === 'https' && self::isTrustedProxy((string) ($server['REMOTE_ADDR'] ?? ''));
    }

    private static function isTrustedProxy(string $remote): bool
    {
        $configured = trim((string) Config::get('TRUSTED_PROXIES', ''));
        if ($configured === '' || $remote === '') {
            return false;
        }
        if ($configured === '*') {
            return true;
        }
        $list = array_map('trim', explode(',', $configured));
        return in_array($remote, $list, true);
    }
}
