<?php

namespace App\Support;

use Illuminate\Encryption\Encrypter;
use Illuminate\Support\Facades\Config;

/**
 * Encrypts / decrypts tenant analytics-DB credentials at rest using a
 * dedicated CLIENT_CREDENTIALS_KEY, deliberately decoupled from APP_KEY.
 * A routine `php artisan key:generate` (or a fresh .env) therefore can never
 * orphan the stored client DB passwords.
 *
 * Rotate only via `php artisan clients:rekey` — see docs/admin-guide.md.
 */
class ClientCredentialCipher
{
    public static function encrypter(): Encrypter
    {
        $key = (string) Config::get('credentials.key', '');
        $raw = $key === '' ? false : base64_decode($key, true);
        if ($raw === false || strlen($raw) !== 32) {
            throw new \RuntimeException(
                'CLIENT_CREDENTIALS_KEY is not configured. Generate one with '
                . '`php artisan clients:key:generate` and set it in .env.'
            );
        }

        return new Encrypter($raw, 'AES-256-CBC');
    }

    public static function encrypt(string $value): string
    {
        return self::encrypter()->encryptString($value);
    }

    public static function decrypt(string $value): string
    {
        return self::encrypter()->decryptString($value);
    }
}