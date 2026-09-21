<?php

declare(strict_types=1);

namespace SimpleKuma\Utils;

/**
 * AES-256-GCM secret encryption for credentials at rest (APP_KEY derived).
 */
class SecretEncryption
{
    private const CIPHER = 'AES-256-GCM';
    private const TAG_LENGTH = 16;

    private static function getEncryptionKey(): string
    {
        $appKey = defined('APP_KEY') ? APP_KEY : '';
        if ($appKey === '') {
            throw new \RuntimeException('APP_KEY is not defined in config.php');
        }

        return substr(hash('sha256', $appKey, true), 0, 32);
    }

    public static function encrypt(string $plaintext): string
    {
        if ($plaintext === '') {
            return '';
        }

        $key = self::getEncryptionKey();
        $iv = random_bytes(openssl_cipher_iv_length(self::CIPHER));

        $encrypted = openssl_encrypt(
            $plaintext,
            self::CIPHER,
            $key,
            OPENSSL_RAW_DATA,
            $iv,
            $tag
        );

        if ($encrypted === false) {
            throw new \RuntimeException('Failed to encrypt secret');
        }

        return base64_encode($iv . $tag . $encrypted);
    }

    public static function decrypt(string $encryptedValue): string
    {
        if ($encryptedValue === '') {
            return '';
        }

        $key = self::getEncryptionKey();
        $combined = base64_decode($encryptedValue, true);

        if ($combined === false) {
            throw new \RuntimeException('Invalid encrypted secret format');
        }

        $ivLength = openssl_cipher_iv_length(self::CIPHER);
        if (strlen($combined) <= $ivLength + self::TAG_LENGTH) {
            throw new \RuntimeException('Invalid encrypted secret length');
        }

        $iv = substr($combined, 0, $ivLength);
        $tag = substr($combined, $ivLength, self::TAG_LENGTH);
        $encrypted = substr($combined, $ivLength + self::TAG_LENGTH);

        $decrypted = openssl_decrypt(
            $encrypted,
            self::CIPHER,
            $key,
            OPENSSL_RAW_DATA,
            $iv,
            $tag
        );

        if ($decrypted === false) {
            throw new \RuntimeException('Failed to decrypt secret');
        }

        return $decrypted;
    }

    /**
     * Decrypt if value looks like our ciphertext; otherwise return as plaintext (legacy rows).
     */
    public static function decryptFlexible(string $value): string
    {
        if ($value === '') {
            return '';
        }

        if (!self::looksEncrypted($value)) {
            return $value;
        }

        try {
            return self::decrypt($value);
        } catch (\Throwable $e) {
            // Legacy plaintext that happens to be valid base64
            return $value;
        }
    }

    public static function looksEncrypted(string $value): bool
    {
        if ($value === '' || strlen($value) < 32) {
            return false;
        }

        $combined = base64_decode($value, true);
        if ($combined === false) {
            return false;
        }

        $ivLength = openssl_cipher_iv_length(self::CIPHER);

        return strlen($combined) > $ivLength + self::TAG_LENGTH;
    }

    public static function mask(string $value): string
    {
        if ($value === '') {
            return '';
        }

        $length = strlen($value);
        if ($length <= 4) {
            return str_repeat('*', $length);
        }

        return str_repeat('*', $length - 4) . substr($value, -4);
    }
}
