<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use App\Services\Auth\Totp;

function totp_assert(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

// ---- 1. RFC 6238 Appendix B test vectors (SHA-1, 8 digits) ----
$rfcSecret = Totp::base32Encode('12345678901234567890');
foreach ([59 => '94287082', 1111111109 => '07081804', 1111111111 => '14050471', 1234567890 => '89005924', 2000000000 => '69279037', 20000000000 => '65353130'] as $time => $expected) {
    totp_assert(Totp::code($rfcSecret, Totp::step($time), 8) === $expected, "RFC 6238 vector at T={$time}");
}

// ---- 2. base32 round trip; a fresh secret is 160 bits ----
$bytes = random_bytes(20);
totp_assert(Totp::base32Decode(Totp::base32Encode($bytes)) === $bytes, 'base32 must round-trip');
totp_assert(Totp::base32Decode('jbsw y3dp-ehpk 3pxp') === Totp::base32Decode('JBSWY3DPEHPK3PXP'), 'spaces, dashes and lower case are tolerated');
$secret = Totp::generateSecret();
totp_assert(strlen($secret) === 32 && strlen(Totp::base32Decode($secret)) === 20, 'a new secret is 20 random bytes (32 base32 chars)');
totp_assert(Totp::generateSecret() !== $secret, 'secrets must be random');

// ---- 3. verify(): ±1 step of drift, nothing further ----
$now = 1_800_000_000;
$step = Totp::step($now);
totp_assert(Totp::verify($secret, Totp::code($secret, $step), $now) === $step, 'the current code is accepted');
totp_assert(Totp::verify($secret, Totp::code($secret, $step - 1), $now) === $step - 1, 'the previous code (30s drift) is accepted');
totp_assert(Totp::verify($secret, Totp::code($secret, $step + 1), $now) === $step + 1, 'the next code (30s drift) is accepted');
totp_assert(Totp::verify($secret, Totp::code($secret, $step - 2), $now) === null, 'a code 60s old is refused');
totp_assert(Totp::verify($secret, Totp::code($secret, $step + 2), $now) === null, 'a code 60s ahead is refused');
$spaced = substr(Totp::code($secret, $step), 0, 3) . ' ' . substr(Totp::code($secret, $step), 3);
totp_assert(Totp::verify($secret, $spaced, $now) === $step, '"123 456" as shown in apps is accepted');
foreach (['', '12345', '1234567', 'abcdef', '12345a'] as $bad) {
    totp_assert(Totp::verify($secret, $bad, $now) === null, "malformed code '{$bad}' is refused");
}

// ---- 4. replay: a step at or before the last used one is refused ----
totp_assert(Totp::verify($secret, Totp::code($secret, $step), $now, 1, $step) === null, 'the same code cannot be used twice');
totp_assert(Totp::verify($secret, Totp::code($secret, $step - 1), $now, 1, $step) === null, 'an older code cannot be used after a newer one');
totp_assert(Totp::verify($secret, Totp::code($secret, $step + 1), $now, 1, $step) === $step + 1, 'the next code still works');

// ---- 5. otpauth URI for the QR code ----
$uri = Totp::provisioningUri('FPDP kukuhtw.com', 'owner@example.com', 'JBSWY3DPEHPK3PXP');
totp_assert(str_starts_with($uri, 'otpauth://totp/FPDP%20kukuhtw.com:owner%40example.com?'), 'label is issuer:account, encoded: ' . $uri);
parse_str((string) parse_url($uri, PHP_URL_QUERY), $query);
totp_assert($query === ['secret' => 'JBSWY3DPEHPK3PXP', 'issuer' => 'FPDP kukuhtw.com', 'algorithm' => 'SHA1', 'digits' => '6', 'period' => '30'], 'URI parameters: ' . json_encode($query));

fwrite(STDOUT, "TotpTest passed\n");
