<?php

declare(strict_types=1);

namespace App\Core;

use RuntimeException;

/**
 * Authenticated symmetric encryption (AES-256-GCM) for secrets that must be
 * stored at rest, such as payment gateway credentials in
 * `payment_gateway_configs.encrypted_value`. Not for passwords (those are
 * hashed with bcrypt, never encrypted/decrypted) or bearer tokens (hashed).
 */
final class Crypto
{
    private const CIPHER = 'aes-256-gcm';
    private const TAG_LENGTH = 16;

    public static function encrypt(string $plaintext): string
    {
        return self::encryptWithKey($plaintext, (string) Config::get('APP_KEY', ''));
    }

    public static function encryptWithKey(string $plaintext, string $appKey): string
    {
        $key = self::deriveKey($appKey);
        $ivLength = openssl_cipher_iv_length(self::CIPHER);
        if ($ivLength === false) {
            throw new RuntimeException('Unable to determine IV length for ' . self::CIPHER);
        }
        $iv = random_bytes($ivLength);

        $tag = '';
        $ciphertext = openssl_encrypt($plaintext, self::CIPHER, $key, OPENSSL_RAW_DATA, $iv, $tag);
        if ($ciphertext === false) {
            throw new RuntimeException('Encryption failed.');
        }

        return base64_encode($iv . $tag . $ciphertext);
    }

    public static function decrypt(string $encoded): string
    {
        return self::decryptWithKey($encoded, (string) Config::get('APP_KEY', ''));
    }

    public static function decryptWithKey(string $encoded, string $appKey): string
    {
        $key = self::deriveKey($appKey);
        $ivLength = openssl_cipher_iv_length(self::CIPHER);
        if ($ivLength === false) {
            throw new RuntimeException('Unable to determine IV length for ' . self::CIPHER);
        }

        $raw = base64_decode($encoded, true);
        if ($raw === false || strlen($raw) <= $ivLength + self::TAG_LENGTH) {
            throw new RuntimeException('Malformed encrypted value.');
        }

        $iv = substr($raw, 0, $ivLength);
        $tag = substr($raw, $ivLength, self::TAG_LENGTH);
        $ciphertext = substr($raw, $ivLength + self::TAG_LENGTH);

        $plaintext = openssl_decrypt($ciphertext, self::CIPHER, $key, OPENSSL_RAW_DATA, $iv, $tag);
        if ($plaintext === false) {
            throw new RuntimeException('Decryption failed: wrong APP_KEY or the stored value was tampered with.');
        }

        return $plaintext;
    }

    /**
     * Derives a 32-byte AES-256 key from APP_KEY (a 64-character hex string
     * per .env.example's generation instructions) via SHA-256, so the raw
     * key material is never used directly regardless of its exact format.
     */
    private static function deriveKey(string $appKey): string
    {
        if ($appKey === '') {
            throw new RuntimeException('APP_KEY is not configured; it is required to encrypt/decrypt stored gateway credentials.');
        }

        return hash('sha256', $appKey, true);
    }
}
