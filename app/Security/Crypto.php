<?php

declare(strict_types=1);

namespace App\Security;

use App\Config\Config;
use RuntimeException;

final class Crypto
{
    /**
     * Encrypt a secret (e.g. UniFi API token) with AES-256-GCM.
     * The key is derived from APP_SECRET.
     */
    public static function encrypt(string $plaintext): string
    {
        $key = self::key();
        $iv = random_bytes(12);
        $tag = '';
        $ciphertext = openssl_encrypt($plaintext, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
        if ($ciphertext === false) {
            throw new RuntimeException('Verschlüsselung fehlgeschlagen');
        }
        return base64_encode($iv . $tag . $ciphertext);
    }

    public static function decrypt(string $payload): string
    {
        $key = self::key();
        $raw = base64_decode($payload, true);
        if ($raw === false || strlen($raw) < 28) {
            throw new RuntimeException('Ungültiger verschlüsselter Wert');
        }
        $iv = substr($raw, 0, 12);
        $tag = substr($raw, 12, 16);
        $ciphertext = substr($raw, 28);
        $plaintext = openssl_decrypt($ciphertext, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
        if ($plaintext === false) {
            throw new RuntimeException('Entschlüsselung fehlgeschlagen');
        }
        return $plaintext;
    }

    private static function key(): string
    {
        $secret = (string) Config::get('APP_SECRET', '');
        if ($secret === '' || $secret === 'change-me-to-a-long-random-string') {
            throw new RuntimeException('APP_SECRET ist nicht konfiguriert. Bitte setzen Sie einen sicheren Wert.');
        }
        return hash('sha256', $secret, true);
    }
}
