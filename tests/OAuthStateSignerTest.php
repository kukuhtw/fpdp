<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use App\Core\Exceptions\UnauthorizedException;
use App\Services\Visitor\OAuthStateSigner;

$signer = new OAuthStateSigner('test-secret');

$state = $signer->sign('alice', 'https://example.test/callback');
$verified = $signer->verify($state);

if ($verified['handle'] !== 'alice' || $verified['redirect_uri'] !== 'https://example.test/callback') {
    fwrite(STDERR, "Sign/verify round trip did not return the original payload\n");
    exit(1);
}

$tampered = substr($state, 0, -1) . (substr($state, -1) === 'a' ? 'b' : 'a');
$rejected = false;
try {
    $signer->verify($tampered);
} catch (UnauthorizedException $e) {
    $rejected = true;
}
if (!$rejected) {
    fwrite(STDERR, "Tampered state was not rejected\n");
    exit(1);
}

$wrongSecretSigner = new OAuthStateSigner('different-secret');
$rejectedWrongSecret = false;
try {
    $wrongSecretSigner->verify($state);
} catch (UnauthorizedException $e) {
    $rejectedWrongSecret = true;
}
if (!$rejectedWrongSecret) {
    fwrite(STDERR, "State signed with a different secret was not rejected\n");
    exit(1);
}

$expiredState = $signer->sign('alice', 'https://example.test/callback', -1);
$rejectedExpired = false;
try {
    $signer->verify($expiredState);
} catch (UnauthorizedException $e) {
    $rejectedExpired = true;
}
if (!$rejectedExpired) {
    fwrite(STDERR, "Expired state was not rejected\n");
    exit(1);
}

fwrite(STDOUT, "OAuth state signer test passed\n");
