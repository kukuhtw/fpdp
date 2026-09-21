<?php

declare(strict_types=1);

namespace App\Services\Payment;

use App\Core\Config;
use App\Core\Http\HttpClient;
use RuntimeException;

final class PaymentCredentialVerifier
{
    public function __construct(private readonly ?HttpClient $http = null)
    {
    }

    /** @param array<string, string> $config */
    public function verify(string $gatewayCode, string $environment, array $config): string
    {
        // Unit/route tests must not depend on remote provider availability.
        if (strtolower((string) Config::get('APP_ENV', 'production')) === 'testing') {
            return 'SKIPPED_TEST_ENVIRONMENT';
        }

        $code = strtoupper($gatewayCode);
        $live = strtoupper($environment) === 'LIVE';
        $response = match ($code) {
            'PAYPAL' => $this->verifyPayPal($live, $config),
            'MIDTRANS' => $this->verifyMidtrans($live, $config),
            'PAYWUZ' => $this->verifyPaywuz($config),
            default => throw new RuntimeException('Credential verification is not supported for this gateway.'),
        };

        if (in_array((int) $response['status'], [401, 403], true)) {
            throw new RuntimeException("{$code} rejected the supplied credentials.");
        }
        if ((int) $response['status'] >= 500) {
            throw new RuntimeException("{$code} credential verification is temporarily unavailable.");
        }

        return match ($code) {
            'PAYPAL' => 'VERIFIED',
            'MIDTRANS' => 'AUTHENTICATED',
            'PAYWUZ' => 'REACHABLE',
        };
    }

    /** @param array<string, string> $config */
    private function verifyPayPal(bool $live, array $config): array
    {
        $base = $live ? 'https://api-m.paypal.com' : 'https://api-m.sandbox.paypal.com';
        $auth = base64_encode($config['client_id'] . ':' . $config['client_secret']);
        $response = $this->client()->request('POST', $base . '/v1/oauth2/token', [
            'Authorization' => 'Basic ' . $auth,
            'Content-Type' => 'application/x-www-form-urlencoded',
            'Accept' => 'application/json',
        ], 'grant_type=client_credentials');
        if ((int) $response['status'] >= 200 && (int) $response['status'] < 300) {
            $payload = json_decode((string) $response['body'], true);
            if (!is_array($payload) || empty($payload['access_token'])) {
                throw new RuntimeException('PayPal authentication response did not contain an access token.');
            }
        }
        return $response;
    }

    /** @param array<string, string> $config */
    private function verifyMidtrans(bool $live, array $config): array
    {
        $base = $live ? 'https://api.midtrans.com/v2' : 'https://api.sandbox.midtrans.com/v2';
        return $this->client()->get($base . '/fpdp-credential-verification/status', [
            'Authorization' => 'Basic ' . base64_encode($config['server_key'] . ':'),
            'Accept' => 'application/json',
        ]);
    }

    /** @param array<string, string> $config */
    private function verifyPaywuz(array $config): array
    {
        return $this->client()->get(rtrim($config['api_url'], '/') . '/', [
            'Authorization' => 'Bearer ' . $config['api_key'],
            'Accept' => 'application/json',
        ]);
    }

    private function client(): HttpClient
    {
        return $this->http ?? new HttpClient();
    }
}
