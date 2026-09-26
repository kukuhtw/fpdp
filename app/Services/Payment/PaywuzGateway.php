<?php

declare(strict_types=1);

namespace App\Services\Payment;

use App\Contracts\PaymentGatewayInterface;
use App\Core\Config;
use App\Core\Http\HttpClient;
use Closure;
use RuntimeException;

/**
 * Paywuz Merchant API v1 adapter.
 *
 * Contract per the Paywuz Merchant API v1 reference (paywuz.id docs):
 *
 * - Auth: `Authorization: Bearer <api key>`; the key prefix picks the
 *   environment (`pk_sand_` sandbox, `pk_live_` production), same base URL.
 * - Envelope: success {"data": {...}}, error {"error": code, "message": text}.
 * - createPayment: POST {base}/transactions with {orderId, amount,
 *   paymentMethod, expiryMinutes, redirectUrl, feeByMerchant, metadata?}.
 *   A repeated orderId returns the existing transaction (HTTP 200).
 * - getPaymentStatus: GET {base}/transactions/{orderId}; status is pending |
 *   success | failed | cancelled (webhooks also report `settlement`, which is
 *   confirmed but not yet in the merchant balance, so it stays PENDING).
 * - cancelPayment: POST {base}/transactions/{orderId}/cancel, pending only.
 * - Every endpoint is keyed by orderId, so external_transaction_id is the
 *   orderId rather than Paywuz's own transaction UUID.
 * - Webhook signature: header `X-Paywuz-Signature: sha256=<hex>`, computed
 *   as HMAC-SHA256 of the raw request body using the API key as the secret.
 * - Webhook events: transaction.settlement, transaction.paid (status
 *   success, the one that means paid), transaction.failed,
 *   transaction.cancelled; payload {"event": ..., "data": {"orderId": ...}}.
 * - Idempotency: header `X-Paywuz-Delivery` identifies a delivery attempt;
 *   when absent, a hash of the raw body is used instead.
 *
 * Paywuz has no refund API; refundPayment() throws so the owner records a
 * refund made outside Paywuz as a manual refund instead.
 */
final class PaywuzGateway implements PaymentGatewayInterface
{
    private const DEFAULT_BASE_URL = 'https://api.paywuz.id/v1';
    private const DEFAULT_EXPIRY_MINUTES = 720;
    private const MAX_ORDER_ID_LENGTH = 64;

    private readonly Closure $httpRequester;

    /**
     * @param array<string, mixed> $configuration Recognized keys: api_key,
     *        api_url (override Config/env), http_requester (test seam: a
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
        return 'Paywuz';
    }

    public function createPayment(array $paymentData): array
    {
        $apiKey = $this->apiKey();
        $orderId = (string) ($paymentData['order_id'] ?? '');
        if ($orderId === '' || strlen($orderId) > self::MAX_ORDER_ID_LENGTH) {
            throw new RuntimeException('Paywuz order_id must be 1-64 characters.');
        }

        $amount = (int) round((float) ($paymentData['amount'] ?? 0));
        $paymentMethod = (string) ($paymentData['payment_method'] ?? 'ALL');
        $expiryMinutes = (int) ($paymentData['expiry_minutes'] ?? self::DEFAULT_EXPIRY_MINUTES);

        $payload = [
            'orderId' => $orderId,
            'amount' => $amount,
            'paymentMethod' => $paymentMethod,
            'expiryMinutes' => $expiryMinutes,
            'redirectUrl' => (string) ($paymentData['redirect_url'] ?? ''),
            'feeByMerchant' => (bool) ($paymentData['fee_by_merchant'] ?? false),
        ];
        if (isset($paymentData['metadata']) && is_array($paymentData['metadata'])) {
            $payload['metadata'] = $paymentData['metadata'];
        }

        $response = ($this->httpRequester)('POST', $this->baseUrl() . '/transactions', [
            'Authorization' => 'Bearer ' . $apiKey,
            'Accept' => 'application/json',
            'Content-Type' => 'application/json',
        ], json_encode($payload));

        $status = (int) ($response['status'] ?? 0);
        $decoded = json_decode((string) ($response['body'] ?? ''), true);
        if ($status < 200 || $status >= 300 || !is_array($decoded)) {
            $message = is_array($decoded) ? ($decoded['message'] ?? null) : null;
            throw new RuntimeException('Paywuz create-transaction failed: ' . ($message ?? "HTTP {$status}"));
        }
        $data = is_array($decoded['data'] ?? null) ? $decoded['data'] : $decoded;

        return [
            'gateway' => 'PAYWUZ',
            'status' => 'PENDING',
            'payment_id' => $orderId,
            'external_transaction_id' => $orderId,
            'order_id' => $orderId,
            'amount' => (float) $amount,
            'currency' => 'IDR',
            'payment_url' => (string) ($data['paymentUrl'] ?? ''),
            'payment_method' => $paymentMethod,
            'expired_at' => isset($data['expiresAt']) ? (string) $data['expiresAt'] : gmdate('c', time() + $expiryMinutes * 60),
        ];
    }

    public function getPaymentStatus(string $externalTransactionId): array
    {
        $data = $this->request('GET', '/transactions/' . rawurlencode($externalTransactionId), 'get-status');

        return [
            'gateway' => 'PAYWUZ',
            'external_transaction_id' => $externalTransactionId,
            'status' => self::mapStatus((string) ($data['status'] ?? '')),
            'amount' => (float) ($data['amount'] ?? 0),
            'currency' => 'IDR',
        ];
    }

    public function cancelPayment(string $externalTransactionId): array
    {
        $data = $this->request('POST', '/transactions/' . rawurlencode($externalTransactionId) . '/cancel', 'cancel');

        return [
            'gateway' => 'PAYWUZ',
            'external_transaction_id' => $externalTransactionId,
            'status' => self::mapStatus((string) ($data['status'] ?? 'cancelled')),
        ];
    }

    public function refundPayment(string $externalTransactionId, float $amount): array
    {
        throw new RuntimeException('Paywuz has no refund API; return the money yourself and record it as a manual refund.');
    }

    public function verifyWebhook(array $headers, string $payload): bool
    {
        $signature = (string) ($headers['x-paywuz-signature'] ?? '');
        if ($signature === '') {
            return false;
        }

        $expected = 'sha256=' . hash_hmac('sha256', $payload, $this->apiKey());

        return hash_equals($expected, $signature);
    }

    public function handleWebhook(array $headers, string $payload): array
    {
        $decoded = json_decode($payload, true);
        $event = is_array($decoded) ? (string) ($decoded['event'] ?? '') : '';
        $data = is_array($decoded['data'] ?? null) ? $decoded['data'] : [];
        $orderId = (string) ($data['orderId'] ?? '');

        $status = match (true) {
            $event === 'transaction.paid' && ($data['status'] ?? '') === 'success' => 'PAID',
            $event === 'transaction.settlement' => 'PENDING',
            $event === 'transaction.failed' => 'FAILED',
            $event === 'transaction.cancelled' => 'CANCELLED',
            default => 'UNKNOWN',
        };

        $deliveryId = (string) ($headers['x-paywuz-delivery'] ?? '');
        $eventId = $deliveryId !== '' ? $deliveryId : hash('sha256', $payload);

        return [
            'event_id' => $eventId,
            'gateway' => 'PAYWUZ',
            'order_id' => $orderId,
            'external_transaction_id' => $orderId,
            'status' => $status,
            'event_type' => $event,
            'occurred_at' => gmdate('c'),
        ];
    }

    /**
     * @return array<string, mixed> the response's `data` object
     */
    private function request(string $method, string $path, string $operation): array
    {
        $response = ($this->httpRequester)($method, $this->baseUrl() . $path, [
            'Authorization' => 'Bearer ' . $this->apiKey(),
            'Accept' => 'application/json',
        ], null);

        $status = (int) ($response['status'] ?? 0);
        $decoded = json_decode((string) ($response['body'] ?? ''), true);
        if ($status < 200 || $status >= 300 || !is_array($decoded)) {
            $message = is_array($decoded) ? ($decoded['message'] ?? $decoded['error'] ?? null) : null;
            throw new RuntimeException("Paywuz {$operation} failed: " . ($message ?? "HTTP {$status}"));
        }

        return is_array($decoded['data'] ?? null) ? $decoded['data'] : [];
    }

    private static function mapStatus(string $status): string
    {
        return match (strtolower($status)) {
            'success' => 'PAID',
            'pending', 'settlement' => 'PENDING',
            'failed' => 'FAILED',
            'cancelled' => 'CANCELLED',
            default => 'UNKNOWN',
        };
    }

    private function apiKey(): string
    {
        $key = (string) ($this->configuration['api_key'] ?? Config::get('PAYWUZ_API_KEY', ''));
        if ($key === '') {
            throw new RuntimeException('PAYWUZ_API_KEY is not configured.');
        }

        return $key;
    }

    private function baseUrl(): string
    {
        return rtrim((string) ($this->configuration['api_url'] ?? Config::get('PAYWUZ_API_URL', self::DEFAULT_BASE_URL)), '/');
    }
}
