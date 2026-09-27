<?php

declare(strict_types=1);

namespace App\Services\Auth;

/**
 * Time-based one-time passwords (RFC 6238, on HOTP RFC 4226): HMAC-SHA1,
 * 6 digits, 30-second steps — what Google Authenticator, Microsoft
 * Authenticator, Authy, 1Password, Bitwarden, and Aegis all use. Nothing
 * here talks to Google or any other service: the phone and the server each
 * compute the code from the shared secret and the current time.
 */
final class Totp
{
    public const PERIOD = 30;
    public const DIGITS = 6;
    private const BASE32 = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    /** A new random secret, base32 (160 bits, the RFC 4226 recommendation). */
    public static function generateSecret(): string
    {
        return self::base32Encode(random_bytes(20));
    }

    public static function step(?int $time = null): int
    {
        return intdiv($time ?? time(), self::PERIOD);
    }

    public static function code(string $base32Secret, int $step, int $digits = self::DIGITS, string $algorithm = 'sha1'): string
    {
        $hash = hash_hmac($algorithm, pack('J', $step), self::base32Decode($base32Secret), true);
        $offset = ord($hash[strlen($hash) - 1]) & 0x0f;
        $binary = ((ord($hash[$offset]) & 0x7f) << 24)
            | (ord($hash[$offset + 1]) << 16)
            | (ord($hash[$offset + 2]) << 8)
            | ord($hash[$offset + 3]);

        return str_pad((string) ($binary % (10 ** $digits)), $digits, '0', STR_PAD_LEFT);
    }

    /**
     * The step a code matches, allowing $window steps of clock drift either
     * way, or null. A step at or before $lastUsedStep is refused, so a code
     * someone saw over your shoulder can't be used again.
     */
    public static function verify(string $base32Secret, string $code, ?int $time = null, int $window = 1, ?int $lastUsedStep = null): ?int
    {
        $code = preg_replace('/\s+/', '', $code) ?? '';
        if (preg_match('/^\d{' . self::DIGITS . '}$/', $code) !== 1) {
            return null;
        }
        $now = self::step($time);
        for ($offset = -$window; $offset <= $window; $offset++) {
            $step = $now + $offset;
            if ($lastUsedStep !== null && $step <= $lastUsedStep) {
                continue;
            }
            if (hash_equals(self::code($base32Secret, $step), $code)) {
                return $step;
            }
        }

        return null;
    }

    /**
     * The otpauth:// URI an authenticator app reads from the QR code.
     */
    public static function provisioningUri(string $issuer, string $account, string $base32Secret): string
    {
        $label = rawurlencode($issuer) . ':' . rawurlencode($account);

        return 'otpauth://totp/' . $label . '?' . http_build_query([
            'secret' => $base32Secret,
            'issuer' => $issuer,
            'algorithm' => 'SHA1',
            'digits' => self::DIGITS,
            'period' => self::PERIOD,
        ], '', '&', PHP_QUERY_RFC3986);
    }

    public static function base32Encode(string $bytes): string
    {
        $bits = '';
        foreach (str_split($bytes) as $byte) {
            $bits .= str_pad(decbin(ord($byte)), 8, '0', STR_PAD_LEFT);
        }
        $out = '';
        foreach (str_split($bits, 5) as $chunk) {
            $out .= self::BASE32[bindec(str_pad($chunk, 5, '0'))];
        }

        return $out;
    }

    public static function base32Decode(string $base32): string
    {
        $base32 = strtoupper(preg_replace('/[\s=-]+/', '', $base32) ?? '');
        $bits = '';
        foreach (str_split($base32) as $char) {
            $value = strpos(self::BASE32, $char);
            if ($value === false) {
                throw new \InvalidArgumentException('Invalid base32 secret.');
            }
            $bits .= str_pad(decbin($value), 5, '0', STR_PAD_LEFT);
        }
        $out = '';
        foreach (str_split($bits, 8) as $byte) {
            if (strlen($byte) === 8) {
                $out .= chr(bindec($byte));
            }
        }

        return $out;
    }
}
