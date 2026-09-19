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
 * Contract verified against a working reference implementation (Paywuz
 * project documentation + `paywuz_request()`/`create_paywuz_subscription()`/
 * `verify_paywuz_signature()` in a sibling codebase), not guessed:
 *
 * - createPayment: POST {base}/transactions, Bearer API key, JSON body
 *   {orderId, amount, paymentMethod, expiryMinutes, redirectUrl,
 *   feeByMerchant, metadata?}. Response envelope is {"data": {...}}; the
 *   only documented response field is `paymentUrl`.
 * - Webhook signature: header `X-Paywuz-Signature: sha256=<hex>`, computed
 *   as HMAC-SHA256 of the raw request body using the API key as the secret.
 * - Webhook payload: {"event": "transaction.paid"|"transaction.failed"|
 *   "transaction.cancelled", "data": {"orderId": "...", "status": "success"}}.
 * - Idempotency: header `X-Paywuz-Delivery` identifies a delivery attempt;
 *   when absent, a hash of the raw body is used instead.
 *
 * Paywuz does not document a transaction-status, cancel, or refund endpoint
 * anywhere in that reference material, so those three interface methods
 * intentionally throw rather than guess at a URL that may not exist.
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
            'external_transaction_id' => isset($data['transactionId']) ? (string) $data['transactionId'] : $orderId,
            'order_id' => $orderId,
            'amount' => (float) $amount,
            'currency' => 'IDR',
            'payment_url' => (string) ($data['paymentUrl'] ?? ''),
            'payment_method' => $paymentMethod,
            'expired_at' => gmdate('c', time() + $expiryMinutes * 60),
        ];
    }

    public function getPaymentStatus(string $externalTransactionId): array
    {
        throw new RuntimeException(
            'Paywuz does not document a transaction-status endpoint; rely on the transaction.paid/'
            . 'transaction.failed/transaction.cancelled webhook instead of polling.',
        );
    }

    public function cancelPayment(string $externalTransactionId): array
    {
        throw new RuntimeException('Paywuz does not document a cancel-transaction endpoint.');
    }

    public function refundPayment(string $externalTransactionId, float $amount): array
    {
        throw new RuntimeException('Paywuz does not document a refund endpoint.');
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
