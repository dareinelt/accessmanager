<?php

declare(strict_types=1);

namespace App\Core;

use App\Config\Config;

final class Logger
{
    private const LEVELS = ['DEBUG' => 0, 'INFO' => 1, 'WARNING' => 2, 'ERROR' => 3];

    public static function debug(string $channel, string $message, array $context = []): void
    {
        self::write('DEBUG', $channel, $message, $context);
    }

    public static function info(string $channel, string $message, array $context = []): void
    {
        self::write('INFO', $channel, $message, $context);
    }

    public static function warning(string $channel, string $message, array $context = []): void
    {
        self::write('WARNING', $channel, $message, $context);
    }

    public static function error(string $channel, string $message, array $context = []): void
    {
        self::write('ERROR', $channel, $message, $context);
    }

    private static function write(string $level, string $channel, string $message, array $context = []): void
    {
        $minimum = self::LEVELS[Config::isDebug() ? 'DEBUG' : 'INFO'];
        if (self::LEVELS[$level] < $minimum) {
            return;
        }

        $dir = dirname(__DIR__, 2) . '/storage/logs';
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }

        $ctx = $context ? ' ' . self::redact((string) json_encode($context, JSON_UNESCAPED_SLASHES)) : '';
        $line = sprintf(
            "[%s] %s.%s %s%s%s",
            date('Y-m-d H:i:s'),
            $level,
            $channel,
            self::redact($message),
            $ctx,
            PHP_EOL
        );

        @file_put_contents($dir . '/app.log', $line, FILE_APPEND | LOCK_EX);
    }

    /**
     * Never let secrets (tokens, passwords, authorization headers) leak into logs.
     */
    private static function redact(string $value): string
    {
        $patterns = [
            '/(api[_-]?token["\':=\s]+)([A-Za-z0-9._-]{6,})/i' => '$1***',
            '/(password["\':=\s]+)([^\s,}"\']+)/i' => '$1***',
            '/(authorization["\':=\s]+)([^\s,}"\']+)/i' => '$1***',
            '/(Bearer\s+)([A-Za-z0-9._-]+)/i' => '$1***',
        ];

        return (string) preg_replace(array_keys($patterns), array_values($patterns), $value);
    }
}
