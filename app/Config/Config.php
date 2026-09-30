<?php

declare(strict_types=1);

namespace App\Config;

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
            if (is_string($v)) {
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
}
