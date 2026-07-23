<?php

declare(strict_types=1);

namespace SimpleKuma\Utils;

/**
 * Proxy password encryption (delegates to SecretEncryption).
 */
class ProxyPasswordEncryption
{
    public static function encrypt(string $password): string
    {
        return SecretEncryption::encrypt($password);
    }

    public static function decrypt(string $encryptedPassword): string
    {
        return SecretEncryption::decrypt($encryptedPassword);
    }

    public static function mask(string $password): string
    {
        return SecretEncryption::mask($password);
    }
}
