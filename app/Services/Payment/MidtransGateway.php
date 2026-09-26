<?php

declare(strict_types=1);

namespace App\Services\Payment;

use App\Contracts\PaymentGatewayInterface;
use App\Core\Config;
use App\Core\Http\HttpClient;
use Closure;
use RuntimeException;

/**
 * Midtrans Snap + Core API adapter.
 *
 * Contract: standard, publicly documented Midtrans behavior (docs.midtrans.com):
 *
 * - createPayment: POST {snap_url}/transactions, HTTP Basic auth (Server Key
 *   as username, empty password), JSON body {transaction_details: {order_id,
 *   gross_amount}, customer_details?}. Response {token, redirect_url}.
 * - getPaymentStatus/cancelPayment/refundPayment: Core API, GET/POST
 *   {api_url}/{order_id}/status|cancel|refund, same Basic auth.
 * - Webhook (HTTP notification): Midtrans POSTs a JSON body directly (no
 *   signature header). Authenticity is verified by recomputing
 *   signature_key = SHA512(order_id + status_code + gross_amount + ServerKey)
 *   and comparing it to the signature_key field carried in that same body.
 * - transaction_status values map to our normalized status as: capture
 *   (+fraud_status=accept) / settlement -> PAID; pending -> PENDING; deny /
 *   expire -> FAILED; cancel -> CANCELLED; refund -> REFUNDED;
 *   partial_refund -> PARTIALLY_REFUNDED (PaymentService::handleWebhook()
 *   moves an already-PAID payment to either).
 */
final class MidtransGateway implements PaymentGatewayInterface
{
    private const SANDBOX_SNAP_URL = 'https://app.sandbox.midtrans.com/snap/v1/transactions';
    private const PRODUCTION_SNAP_URL = 'https://app.midtrans.com/snap/v1/transactions';
    private const SANDBOX_API_URL = 'https://api.sandbox.midtrans.com/v2';
    private const PRODUCTION_API_URL = 'https://api.midtrans.com/v2';
    private const MAX_ORDER_ID_LENGTH = 50;
    private const SNAP_TOKEN_TTL_SECONDS = 86400;

    private readonly Closure $httpRequester;

    /**
     * @param array<string, mixed> $configuration Recognized keys: server_key,
     *        environment (SANDBOX|PRODUCTION), snap_url, api_url (all
     *        override Config/env), http_requester (test seam: a
     *        closure(string $method, string $url, array $headers, ?string
     *        $body): array{status:int, body:string}, defaults to a real
     *        HttpClient call).
     */
    public function __construct(private readonly array $configuration = [])
    {
        $requester = $configuration['http_requester'] ?? null;
        $this->httpRequester = $requester instanceof Closure
            ? $requester
            : static function (string $method, string $url, array $headers, ?string $body): array {
                return (new HttpClient())->request($method, $url, $headers, $body);
            };
    }

    public function getName(): string
    {
        return 'Midtrans';
    }

    public function createPayment(array $paymentData): array
    {
        $orderId = (string) ($paymentData['order_id'] ?? '');
        if ($orderId === '' || strlen($orderId) > self::MAX_ORDER_ID_LENGTH) {
            throw new RuntimeException('Midtrans order_id must be 1-50 characters.');
        }

        $amount = (int) round((float) ($paymentData['amount'] ?? 0));
        $payload = [
            'transaction_details' => ['order_id' => $orderId, 'gross_amount' => $amount],
        ];
        $email = $paymentData['payer_email'] ?? null;
        if (is_string($email) && $email !== '') {
            $payload['customer_details'] = ['email' => $email];
            if (isset($paymentData['payer_name'])) {
                $payload['customer_details']['first_name'] = (string) $paymentData['payer_name'];
            }
        }

        $response = ($this->httpRequester)('POST', $this->snapUrl(), $this->authHeaders(true), json_encode($payload));
        $decoded = $this->decodeOrFail($response, 'create-transaction');
        if (!isset($decoded['token'])) {
            throw new RuntimeException('Midtrans create-transaction response did not include a token.');
        }

        return [
            'gateway' => 'MIDTRANS',
            'status' => 'PENDING',
            'payment_id' => $orderId,
            'external_transaction_id' => $orderId,
            'order_id' => $orderId,
            'amount' => (float) $amount,
            'currency' => 'IDR',
            'payment_url' => (string) ($decoded['redirect_url'] ?? ''),
            'payment_method' => 'SNAP',
            'expired_at' => gmdate('c', time() + self::SNAP_TOKEN_TTL_SECONDS),
            'snap_token' => (string) $decoded['token'],
        ];
    }

    public function getPaymentStatus(string $externalTransactionId): array
    {
        $response = ($this->httpRequester)(
            'GET',
            $this->apiUrl() . '/' . rawurlencode($externalTransactionId) . '/status',
            $this->authHeaders(false),
            null,
        );
        $decoded = $this->decodeOrFail($response, 'get-status');

        return [
            'gateway' => 'MIDTRANS',
            'external_transaction_id' => $externalTransactionId,
            'status' => self::mapTransactionStatus(
                (string) ($decoded['transaction_status'] ?? ''),
                (string) ($decoded['fraud_status'] ?? ''),
            ),
            'amount' => (float) ($decoded['gross_amount'] ?? 0),
            'currency' => (string) ($decoded['currency'] ?? 'IDR'),
        ];
    }

    public function cancelPayment(string $externalTransactionId): array
    {
        $response = ($this->httpRequester)(
            'POST',
            $this->apiUrl() . '/' . rawurlencode($externalTransactionId) . '/cancel',
            $this->authHeaders(false),
            null,
        );
        $this->decodeOrFail($response, 'cancel');

        return [
            'gateway' => 'MIDTRANS',
            'external_transaction_id' => $externalTransactionId,
            'status' => 'CANCELLED',
        ];
    }

    public function refundPayment(string $externalTransactionId, float $amount): array
    {
        $response = ($this->httpRequester)(
            'POST',
            $this->apiUrl() . '/' . rawurlencode($externalTransactionId) . '/refund',
            $this->authHeaders(true),
            json_encode(['amount' => (int) round($amount), 'reason' => 'Refund requested']),
        );
        $this->decodeOrFail($response, 'refund');

        return [
            'gateway' => 'MIDTRANS',
            'external_transaction_id' => $externalTransactionId,
            'status' => 'REFUNDED',
            'amount' => $amount,
        ];
    }

    public function verifyWebhook(array $headers, string $payload): bool
    {
        $decoded = json_decode($payload, true);
        if (!is_array($decoded)) {
            return false;
        }

        $orderId = (string) ($decoded['order_id'] ?? '');
        $statusCode = (string) ($decoded['status_code'] ?? '');
        $grossAmount = (string) ($decoded['gross_amount'] ?? '');
        $signatureKey = (string) ($decoded['signature_key'] ?? '');
        if ($orderId === '' || $statusCode === '' || $grossAmount === '' || $signatureKey === '') {
            return false;
        }

        $expected = hash('sha512', $orderId . $statusCode . $grossAmount . $this->serverKey());

        return hash_equals($expected, $signatureKey);
    }

    public function handleWebhook(array $headers, string $payload): array
    {
        $decoded = json_decode($payload, true);
        $data = is_array($decoded) ? $decoded : [];
        $orderId = (string) ($data['order_id'] ?? '');
        $transactionStatus = (string) ($data['transaction_status'] ?? '');
        $fraudStatus = (string) ($data['fraud_status'] ?? '');

        return [
            // Midtrans reuses transaction_id for every notification about the
            // same transaction (pending, settlement, refund, ...), so it alone
            // would dedupe a later refund as a repeat of the settlement. Keying
            // on id + status keeps real retries deduplicated; refunds also mix
            // in the payload, since one transaction can be partially refunded
            // several times.
            'event_id' => isset($data['transaction_id'])
                ? (string) $data['transaction_id'] . ':' . $transactionStatus
                    . (str_contains($transactionStatus, 'refund') ? ':' . substr(hash('sha256', $payload), 0, 16) : '')
                : hash('sha256', $payload),
            'gateway' => 'MIDTRANS',
            'order_id' => $orderId,
            'external_transaction_id' => $orderId,
            'status' => self::mapTransactionStatus($transactionStatus, $fraudStatus),
            'event_type' => $transactionStatus,
            'occurred_at' => gmdate('c'),
        ];
    }

    private static function mapTransactionStatus(string $transactionStatus, string $fraudStatus): string
    {
        return match (true) {
            $transactionStatus === 'capture' && $fraudStatus === 'accept' => 'PAID',
            $transactionStatus === 'capture' => 'PENDING',
            $transactionStatus === 'settlement' => 'PAID',
            $transactionStatus === 'pending' => 'PENDING',
            $transactionStatus === 'deny' => 'FAILED',
            $transactionStatus === 'cancel' => 'CANCELLED',
            $transactionStatus === 'expire' => 'FAILED',
            $transactionStatus === 'refund' => 'REFUNDED',
            $transactionStatus === 'partial_refund' => 'PARTIALLY_REFUNDED',
            default => 'UNKNOWN',
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeOrFail(array $response, string $operation): array
    {
        $status = (int) ($response['status'] ?? 0);
        $decoded = json_decode((string) ($response['body'] ?? ''), true);
        if ($status < 200 || $status >= 300 || !is_array($decoded)) {
            $message = is_array($decoded) ? ($decoded['status_message'] ?? ($decoded['error_messages'][0] ?? null)) : null;
            throw new RuntimeException("Midtrans {$operation} failed: " . ($message ?? "HTTP {$status}"));
        }

        return $decoded;
    }

    /**
     * @return array<string, string>
     */
    private function authHeaders(bool $withContentType): array
    {
        $headers = [
            'Authorization' => 'Basic ' . base64_encode($this->serverKey() . ':'),
            'Accept' => 'application/json',
        ];
        if ($withContentType) {
            $headers['Content-Type'] = 'application/json';
        }

        return $headers;
    }

    private function serverKey(): string
    {
        $key = (string) ($this->configuration['server_key'] ?? Config::get('MIDTRANS_SERVER_KEY', ''));
        if ($key === '') {
            throw new RuntimeException('MIDTRANS_SERVER_KEY is not configured.');
        }

        return $key;
    }

    private function isProduction(): bool
    {
        $env = strtoupper((string) ($this->configuration['environment'] ?? Config::get('MIDTRANS_ENVIRONMENT', 'SANDBOX')));

        return $env === 'PRODUCTION' || $env === 'PROD';
    }

    private function snapUrl(): string
    {
        $default = $this->isProduction() ? self::PRODUCTION_SNAP_URL : self::SANDBOX_SNAP_URL;

        return (string) ($this->configuration['snap_url'] ?? Config::get('MIDTRANS_SNAP_URL', $default));
    }

    private function apiUrl(): string
    {
        $default = $this->isProduction() ? self::PRODUCTION_API_URL : self::SANDBOX_API_URL;

        return rtrim((string) ($this->configuration['api_url'] ?? Config::get('MIDTRANS_API_URL', $default)), '/');
    }
}
