<?php

declare(strict_types=1);

if (!function_exists('e')) {
    /**
     * HTML-escape a value for safe output.
     */
    function e(mixed $value): string
    {
        return htmlspecialchars((string) ($value ?? ''), ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('json_attr')) {
    /**
     * JSON-encode a value for embedding inside an HTML attribute.
     */
    function json_attr(mixed $value): string
    {
        return e(json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }
}

if (!function_exists('format_date')) {
    /**
     * Format a datetime string for display.
     */
    function format_date(?string $datetime, string $format = 'd.m.Y H:i'): string
    {
        if (!$datetime) {
            return '–';
        }
        $ts = strtotime($datetime);
        return $ts ? date($format, $ts) : '–';
    }
}

if (!function_exists('time_ago')) {
    /**
     * Human-readable relative time (e.g. "vor 3 Minuten").
     */
    function time_ago(?string $datetime): string
    {
        if (!$datetime) {
            return '–';
        }
        $ts = strtotime($datetime);
        if (!$ts) {
            return '–';
        }
        $diff = time() - $ts;
        if ($diff < 0) {
            $diff = 0;
        }
        if ($diff < 60) {
            return 'gerade eben';
        }
        if ($diff < 3600) {
            $m = (int) floor($diff / 60);
            return "vor {$m} " . ($m === 1 ? 'Minute' : 'Minuten');
        }
        if ($diff < 86400) {
            $h = (int) floor($diff / 3600);
            return "vor {$h} " . ($h === 1 ? 'Stunde' : 'Stunden');
        }
        $d = (int) floor($diff / 86400);
        return "vor {$d} " . ($d === 1 ? 'Tag' : 'Tagen');
    }
}
