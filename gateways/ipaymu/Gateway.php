<?php

declare(strict_types=1);

namespace FpdpGatewayPlugins\Ipaymu;

use App\Contracts\PaymentGatewayInterface;
use App\Core\Config;
use App\Core\Http\HttpClient;
use Closure;
use RuntimeException;

/**
 * iPaymu API v2 adapter (docs.ipaymu.com).
 *
 * Contract, verified against iPaymu's official sample code
 * (github.com/ipaymu/ipaymu-payment-v2-sample-php and
 * github.com/ipaymu/ipaymu-php-api) and callback documentation
 * (docs.ipaymu.com/id/docs/callback):
 *
 * - createPayment: POST {base}/payment ("Redirect Payment" — the only mode
 *   this adapter uses, since it needs no payment_method/channel chosen up
 *   front). Body: product[]/qty[]/price[] (one line item built from
 *   amount/description), returnUrl, cancelUrl, notifyUrl, referenceId.
 *   Every request is authenticated with three headers: `va` (merchant's
 *   iPaymu VA number), `signature` (hex HMAC-SHA256 of
 *   "METHOD:va:sha256_hex(body_json):apiKey", keyed by apiKey), `timestamp`
 *   (YmdHis, UTC). Response envelope: {Status, Message, Data: {SessionID,
 *   Url}}; Data.Url is where the buyer is redirected to pay.
 * - getPaymentStatus: POST {base}/transaction, body {transactionId}, same
 *   signed-header scheme (confirmed via the official SDK's
 *   checkTransaction()). iPaymu does not publish the Data field names for
 *   this response beyond the {Status, Message, Data} envelope, so this
 *   adapter reuses the status/status_code vocabulary documented for the
 *   callback (see below) against whatever field is present in Data, and
 *   falls back to PENDING — never silently PAID — if neither is present.
 *   Verify the real field name against a sandbox response before relying
 *   on this in production.
 * - cancelPayment/refundPayment: iPaymu's v2 API has no documented
 *   cancel/refund endpoint (only balance/transaction/history/banklist/
 *   payment/payment-direct/cod-*), so both throw rather than fabricate a
 *   fake success — matches capabilities.supports_refund=false in
 *   gateway.json.
 * - verifyWebhook/handleWebhook: per docs.ipaymu.com/id/docs/callback,
 *   iPaymu POSTs the transaction as form-urlencoded fields (the documented
 *   default; a JSON body, the documented alternative, is also accepted)
 *   including trx_id, reference_id, status, status_code, amount, fee,
 *   paid_off, buyer_name/email/phone. Authenticity is verified by
 *   normalizing field types (trx_id/status_code/paid_off to int, is_escrow
 *   to bool), sorting keys ascending, json_encode-ing (PHP already
 *   backslash-escapes "/" by default, matching the documented step), then
 *   comparing hex HMAC-SHA256 of that string — keyed by the merchant's VA
 *   number, NOT the API key — against the `X-Signature` header.
 *   status_code: 1=success (PAID), 0=pending, -2=expired (FAILED).
 */
final class Gateway implements PaymentGatewayInterface
{
    private const SANDBOX_BASE_URL = 'https://sandbox.ipaymu.com/api/v2';
    private const PRODUCTION_BASE_URL = 'https://my.ipaymu.com/api/v2';
    private const MAX_ORDER_ID_LENGTH = 64;

    private readonly Closure $httpRequester;

    /**
     * @param array<string, mixed> $configuration Recognized keys: va,
     *        api_key, environment (SANDBOX|PRODUCTION, default SANDBOX),
     *        http_requester (test seam: closure(string $method, string
     *        $url, array $headers, ?string $body): array{status:int,
     *        body:string}, defaults to a real HttpClient call).
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
        return 'iPaymu';
    }

    public function createPayment(array $paymentData): array
    {
        $orderId = (string) ($paymentData['order_id'] ?? '');
        if ($orderId === '' || strlen($orderId) > self::MAX_ORDER_ID_LENGTH) {
            throw new RuntimeException('iPaymu order_id must be 1-64 characters.');
        }

        $amount = round((float) ($paymentData['amount'] ?? 0), 2);
        if ($amount <= 0) {
            throw new RuntimeException('iPaymu createPayment requires a positive amount.');
        }
        $description = (string) ($paymentData['description'] ?? ('Order ' . $orderId));
        $returnUrl = (string) ($paymentData['return_url'] ?? $this->defaultUrl());
        $cancelUrl = (string) ($paymentData['cancel_url'] ?? $paymentData['return_url'] ?? $this->defaultUrl());

        $body = [
            'product' => [$description],
            'qty' => [1],
            'price' => [$amount],
            'returnUrl' => $returnUrl,
            'cancelUrl' => $cancelUrl,
            'notifyUrl' => $this->notifyUrl(),
            'referenceId' => $orderId,
        ];
        $buyerEmail = $paymentData['payer_email'] ?? null;
        if (is_string($buyerEmail) && $buyerEmail !== '') {
            $body['buyerEmail'] = $buyerEmail;
            if (isset($paymentData['payer_name'])) {
                $body['buyerName'] = (string) $paymentData['payer_name'];
            }
        }

        $response = ($this->httpRequester)(
            'POST',
            $this->baseUrl() . '/payment',
            $this->signedHeaders('POST', $body),
            json_encode($body, JSON_UNESCAPED_SLASHES),
        );
        $decoded = $this->decodeOrFail($response, 'create-payment');
        $data = is_array($decoded['Data'] ?? null) ? $decoded['Data'] : [];
        $paymentUrl = (string) ($data['Url'] ?? '');
        if ($paymentUrl === '') {
            throw new RuntimeException('iPaymu create-payment response did not include a checkout Url.');
        }

        return [
            'gateway' => 'IPAYMU',
            'status' => 'PENDING',
            'payment_id' => (string) ($data['SessionID'] ?? $orderId),
            'external_transaction_id' => isset($data['SessionID']) ? (string) $data['SessionID'] : null,
            'order_id' => $orderId,
            'amount' => $amount,
            'currency' => 'IDR',
            'payment_url' => $paymentUrl,
            'payment_method' => 'IPAYMU_REDIRECT',
            'session_id' => (string) ($data['SessionID'] ?? ''),
        ];
    }

    public function getPaymentStatus(string $externalTransactionId): array
    {
        $body = ['transactionId' => $externalTransactionId];
        $response = ($this->httpRequester)(
            'POST',
            $this->baseUrl() . '/transaction',
            $this->signedHeaders('POST', $body),
            json_encode($body, JSON_UNESCAPED_SLASHES),
        );
        $decoded = $this->decodeOrFail($response, 'get-status');
        $data = is_array($decoded['Data'] ?? null) ? $decoded['Data'] : [];

        return [
            'gateway' => 'IPAYMU',
            'external_transaction_id' => $externalTransactionId,
            'status' => self::mapStatus($data['status_code'] ?? $data['Status'] ?? null, $data['status'] ?? null),
            'amount' => (float) ($data['amount'] ?? $data['Amount'] ?? 0),
            'currency' => 'IDR',
        ];
    }

    public function cancelPayment(string $externalTransactionId): array
    {
        throw new RuntimeException('iPaymu API v2 does not expose a cancel-transaction endpoint; let the payment expire instead.');
    }

    public function refundPayment(string $externalTransactionId, float $amount): array
    {
        throw new RuntimeException('iPaymu API v2 does not expose a refund endpoint; refunds must be issued manually from the iPaymu merchant dashboard.');
    }

    public function verifyWebhook(array $headers, string $payload): bool
    {
        $va = (string) ($this->configuration['va'] ?? '');
        if ($va === '') {
            return false;
        }
        $signature = (string) ($headers['x-signature'] ?? '');
        if ($signature === '') {
            return false;
        }

        $data = self::parseCallbackPayload($payload);
        if ($data === null) {
            return false;
        }

        $expected = hash_hmac('sha256', self::canonicalizeCallback($data), $va);

        return hash_equals($expected, $signature);
    }

    public function handleWebhook(array $headers, string $payload): array
    {
        $data = self::parseCallbackPayload($payload) ?? [];

        return [
            'event_id' => isset($data['trx_id']) ? 'ipaymu-' . (string) $data['trx_id'] : hash('sha256', $payload),
            'gateway' => 'IPAYMU',
            'order_id' => (string) ($data['reference_id'] ?? ''),
            'external_transaction_id' => isset($data['sid'])
                ? (string) $data['sid']
                : (isset($data['trx_id']) ? (string) $data['trx_id'] : null),
            'status' => self::mapStatus($data['status_code'] ?? null, $data['status'] ?? null),
            'event_type' => (string) ($data['status'] ?? 'callback'),
            'amount' => isset($data['amount']) ? (float) $data['amount'] : (isset($data['total']) ? (float) $data['total'] : 0.0),
            'occurred_at' => gmdate('c'),
        ];
    }

    /**
     * @param array<string, mixed> $body
     * @return array<string, string>
     */
    private function signedHeaders(string $method, array $body): array
    {
        $jsonBody = (string) json_encode($body, JSON_UNESCAPED_SLASHES);
        $requestBodyHash = strtolower(hash('sha256', $jsonBody));
        $stringToSign = strtoupper($method) . ':' . $this->va() . ':' . $requestBodyHash . ':' . $this->apiKey();

        return [
            'Accept' => 'application/json',
            'Content-Type' => 'application/json',
            'va' => $this->va(),
            'signature' => hash_hmac('sha256', $stringToSign, $this->apiKey()),
            'timestamp' => gmdate('YmdHis'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeOrFail(array $response, string $operation): array
    {
        $status = (int) ($response['status'] ?? 0);
        $decoded = json_decode((string) ($response['body'] ?? ''), true);
        if ($status < 200 || $status >= 300 || !is_array($decoded) || (int) ($decoded['Status'] ?? 200) >= 400) {
            $message = is_array($decoded) ? ($decoded['Message'] ?? null) : null;
            throw new RuntimeException("iPaymu {$operation} failed: " . ($message ?? "HTTP {$status}"));
        }

        return $decoded;
    }

    private static function mapStatus(mixed $statusCode, mixed $statusText): string
    {
        if ($statusCode !== null && $statusCode !== '') {
            return match ((int) $statusCode) {
                1 => 'PAID',
                0 => 'PENDING',
                -2 => 'FAILED',
                default => 'PENDING',
            };
        }

        return match (strtolower((string) $statusText)) {
            'berhasil', 'success', 'settlement' => 'PAID',
            'expired', 'gagal', 'failed' => 'FAILED',
            default => 'PENDING',
        };
    }

    /**
     * Parses iPaymu's callback body — form-urlencoded is iPaymu's documented
     * default ("Direkomendasikan untuk stabilitas"); a JSON body (the
     * documented alternative) is also accepted.
     *
     * @return array<string, mixed>|null
     */
    private static function parseCallbackPayload(string $payload): ?array
    {
        $trimmed = ltrim($payload);
        if ($trimmed === '') {
            return null;
        }

        if ($trimmed[0] === '{' || $trimmed[0] === '[') {
            $decoded = json_decode($payload, true);

            return is_array($decoded) ? $decoded : null;
        }

        parse_str($payload, $data);

        return $data === [] ? null : $data;
    }

    /**
     * Reproduces docs.ipaymu.com/id/docs/callback's signing steps: coerce
     * trx_id/status_code/transaction_status_code/paid_off to int and
     * is_escrow to bool, sort keys ascending, then json_encode (PHP already
     * escapes "/" as "\/" by default, matching the documented step).
     *
     * @param array<string, mixed> $data
     */
    private static function canonicalizeCallback(array $data): string
    {
        foreach (['trx_id', 'status_code', 'transaction_status_code', 'paid_off'] as $intField) {
            if (isset($data[$intField])) {
                $data[$intField] = (int) $data[$intField];
            }
        }
        if (isset($data['is_escrow'])) {
            $data['is_escrow'] = !in_array(strtolower((string) $data['is_escrow']), ['0', 'false', ''], true);
        }
        if (isset($data['additional_info']) && !is_array($data['additional_info'])) {
            $decoded = json_decode((string) $data['additional_info'], true);
            $data['additional_info'] = is_array($decoded) ? $decoded : [];
        }

        ksort($data, SORT_STRING);

        return (string) json_encode($data);
    }

    private function va(): string
    {
        $value = (string) ($this->configuration['va'] ?? '');
        if ($value === '') {
            throw new RuntimeException('iPaymu plugin gateway is missing its "va" credential.');
        }

        return $value;
    }

    private function apiKey(): string
    {
        $value = (string) ($this->configuration['api_key'] ?? '');
        if ($value === '') {
            throw new RuntimeException('iPaymu plugin gateway is missing its "api_key" credential.');
        }

        return $value;
    }

    private function isProduction(): bool
    {
        return strtoupper((string) ($this->configuration['environment'] ?? 'SANDBOX')) === 'PRODUCTION';
    }

    private function baseUrl(): string
    {
        return $this->isProduction() ? self::PRODUCTION_BASE_URL : self::SANDBOX_BASE_URL;
    }

    private function notifyUrl(): string
    {
        return $this->siteOrigin() . '/api/v1/payments/webhook/IPAYMU';
    }

    private function defaultUrl(): string
    {
        return $this->siteOrigin() . '/';
    }

    private function siteOrigin(): string
    {
        return 'https://' . Config::get('NODE_DOMAIN', 'localhost');
    }
}
