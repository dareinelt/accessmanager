<?php

declare(strict_types=1);

namespace App\Security;

use App\Core\Session;

final class Csrf
{
    public static function token(): string
    {
        $token = Session::get('_csrf');
        if (!is_string($token) || $token === '') {
            $token = bin2hex(random_bytes(32));
            Session::put('_csrf', $token);
        }
        return $token;
    }

    public static function field(): string
    {
        return '<input type="hidden" name="_csrf" value="' . e(self::token()) . '">';
    }

    public static function meta(): string
    {
        return '<meta name="csrf-token" content="' . e(self::token()) . '">';
    }

    public static function validate(?string $token): bool
    {
        $expected = Session::get('_csrf');
        if (!is_string($expected) || !is_string($token) || $token === '') {
            return false;
        }
        return hash_equals($expected, $token);
    }
}
