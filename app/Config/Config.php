<?php

declare(strict_types=1);

namespace App\Config;

use App\Security\Crypto;

final class Config
{
    private static ?array $items = null;

    public static function load(string $envPath): void
    {
        if (self::$items !== null) {
            return;
        }

        $items = [];

        // 1. Read .env file (lowest precedence).
        if (is_file($envPath)) {
            foreach (file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
                $line = trim($line);
                if ($line === '' || str_starts_with($line, '#')) {
                    continue;
                }
                if (!str_contains($line, '=')) {
                    continue;
                }
                [$key, $value] = explode('=', $line, 2);
                $key = trim($key);
                $value = trim($value);
                $len = strlen($value);
                if ($len >= 2) {
                    $first = $value[0];
                    if (($first === '"' || $first === "'") && $value[$len - 1] === $first) {
                        $value = substr($value, 1, -1);
                    }
                }
                $items[$key] = $value;
            }
        }

        // 2. Override with real environment variables (getenv + $_ENV + $_SERVER).
        foreach ($_ENV as $k => $v) {
            if (is_scalar($v)) {
                $items[$k] = (string) $v;
            }
        }
        foreach ($_SERVER as $k => $v) {
            // SECURITY FIX: request headers (HTTP_*) are client-controlled and
            // must never be able to shadow configuration values.
            if (is_string($v) && !str_starts_with((string) $k, 'HTTP_')) {
                $items[$k] = $v;
            }
        }
        // getenv() values take highest precedence.
        foreach ($items as $k => $v) {
            $env = getenv($k);
            if ($env !== false) {
                $items[$k] = $env;
            }
        }

        self::$items = $items;
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        return self::$items[$key] ?? $default;
    }

    public static function has(string $key): bool
    {
        return isset(self::$items[$key]);
    }

    public static function bool(string $key, bool $default = false): bool
    {
        $v = self::get($key, $default);
        if (is_bool($v)) {
            return $v;
        }
        return in_array(strtolower((string) $v), ['1', 'true', 'yes', 'on'], true);
    }

    public static function int(string $key, int $default = 0): int
    {
        return (int) self::get($key, $default);
    }

    public static function isDebug(): bool
    {
        return self::bool('APP_DEBUG', false);
    }

    public static function isMock(): bool
    {
        return self::bool('UNIFI_API_MOCK', false);
    }

    public static function appName(): string
    {
        return (string) self::get('APP_NAME', 'UniFi Access Manager');
    }

    /**
     * Active Directory / LDAP settings as a single normalised array.
     *
     * @return array<string,mixed>
     */
    public static function ldap(): array
    {
        return [
            'host' => (string) self::get('LDAP_HOST', ''),
            'port' => self::int('LDAP_PORT', 389),
            'base_dn' => (string) self::get('LDAP_BASE_DN', ''),
            'bind_dn' => (string) self::get('LDAP_BIND_DN', ''),
            'bind_password' => self::ldapBindPassword(),
            'use_tls' => self::bool('LDAP_USE_TLS', false),
            'card_attribute' => (string) self::get('LDAP_CARD_ATTRIBUTE', 'employeeID'),
            'user_filter' => (string) self::get('LDAP_USER_FILTER', '(&(objectCategory=person)(objectClass=user))'),
            'group_filter' => (string) self::get('LDAP_GROUP_FILTER', '(objectClass=group)'),
            'group_base_dn' => (string) self::get('LDAP_GROUP_BASE_DN', ''),
            'mock' => self::bool('LDAP_MOCK', false),
        ];
    }

    /**
     * Resolve the LDAP bind password: an encrypted value (LDAP_BIND_PASSWORD_ENC)
     * takes precedence over the plaintext LDAP_BIND_PASSWORD.
     */
    public static function ldapBindPassword(): string
    {
        $enc = (string) self::get('LDAP_BIND_PASSWORD_ENC', '');
        if ($enc !== '') {
            return Crypto::decrypt($enc);
        }
        return (string) self::get('LDAP_BIND_PASSWORD', '');
    }
}
