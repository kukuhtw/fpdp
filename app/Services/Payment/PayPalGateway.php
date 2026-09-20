<?php

declare(strict_types=1);

namespace App\Services\Payment;

use App\Contracts\PaymentGatewayInterface;
use App\Core\Config;
use App\Core\Http\HttpClient;
use Closure;
use RuntimeException;

/**
 * PayPal Orders API v2 adapter (developer.paypal.com/docs/api/orders/v2).
 *
 * Contract: standard, publicly documented PayPal REST behavior.
 *
 * - Auth: OAuth2 client-credentials grant, POST {base}/v1/oauth2/token,
 *   HTTP Basic (client_id:client_secret), body
 *   `grant_type=client_credentials`. A fresh token is requested per call
 *   (no caching) since this adapter is stateless between requests.
 * - createPayment: POST {base}/v2/checkout/orders, Bearer token, intent
 *   CAPTURE, one purchase unit. Response carries an approval link
 *   (`links[].rel === "approve"`) the buyer must open; an order with no
 *   approval within ~3 hours simply expires on PayPal's side — there is no
 *   separate "create" confirmation step.
 * - Two-step capture: unlike Midtrans/Paywuz, an approved PayPal order is
 *   NOT paid until the merchant explicitly calls the capture endpoint.
 *   PaymentGatewayInterface has no separate capture step, so this adapter
 *   captures lazily inside getPaymentStatus(): if the order is APPROVED
 *   when polled, it is captured on the spot and the resulting status is
 *   returned. The PAYMENT.CAPTURE.COMPLETED webhook covers a capture
 *   triggered any other way (e.g. manually in the PayPal dashboard).
 * - getPaymentStatus/refundPayment: GET {base}/v2/checkout/orders/{id} to
 *   read order/capture state; refund targets the *capture* id
 *   (`purchase_units[0].payments.captures[0].id`), not the order id, so
 *   refundPayment looks that up first via the same GET.
 * - cancelPayment: PayPal's REST API has no "cancel a CAPTURE-intent
 *   order" endpoint (only an AUTHORIZE-intent *authorization* can be
 *   voided, which this adapter does not use) — an unapproved order is left
 *   to expire instead, so this intentionally throws rather than guess at
 *   an endpoint that does not exist.
 * - Webhook signature: PayPal does not support recomputing a local HMAC;
 *   verification is itself an API call — POST
 *   {base}/v1/notifications/verify-webhook-signature with the
 *   `paypal-*` request headers plus the configured `webhook_id` and the
 *   decoded event body, expecting `verification_status: "SUCCESS"`.
 * - Webhook payload: `{id, event_type, resource, create_time}`. For
 *   CHECKOUT.ORDER.* events `resource` IS the order (`resource.id` is the
 *   order id); for PAYMENT.CAPTURE.* events `resource` is the capture, and
 *   the order id lives at
 *   `resource.supplementary_data.related_ids.order_id`.
 * - Currency: PayPal does not support every ISO 4217 code, and critically
 *   for this project **does not support IDR** (or INR) for receiving
 *   payments at all. createPayment defaults to USD and rejects any
 *   currency outside PayPal's documented supported list rather than
 *   silently accepting one that would fail at PayPal's end.
 */
final class PayPalGateway implements PaymentGatewayInterface
{
    private const SANDBOX_BASE_URL = 'https://api-m.sandbox.paypal.com';
    private const PRODUCTION_BASE_URL = 'https://api-m.paypal.com';
    private const MAX_ORDER_ID_LENGTH = 127;
    private const ORDER_EXPIRY_SECONDS = 3 * 3600;
    private const DEFAULT_CURRENCY = 'USD';

    /**
     * PayPal's documented zero-decimal currencies for the Orders API: the
     * amount `value` is a bare integer string, no decimal point.
     */
    private const ZERO_DECIMAL_CURRENCIES = ['HUF', 'JPY', 'TWD'];

    /**
     * PayPal's publicly documented supported currencies
     * (developer.paypal.com/reference/currency-codes). IDR and INR are
     * deliberately absent — PayPal does not support receiving payments in
     * either currency.
     */
    private const SUPPORTED_CURRENCIES = [
        'AUD', 'BRL', 'CAD', 'CNY', 'CZK', 'DKK', 'EUR', 'HKD', 'HUF', 'ILS',
        'JPY', 'MYR', 'MXN', 'TWD', 'NZD', 'NOK', 'PHP', 'PLN', 'GBP', 'SGD',
        'SEK', 'CHF', 'THB', 'USD',
    ];

    private readonly Closure $httpRequester;

    /**
     * @param array<string, mixed> $configuration Recognized keys: client_id,
     *        client_secret, webhook_id, environment (SANDBOX|PRODUCTION),
     *        api_url (all override Config/env), http_requester (test seam:
     *        a closure(string $method, string $url, array $headers, ?string
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
        return 'PayPal';
    }

    public function createPayment(array $paymentData): array
    {
        $orderId = (string) ($paymentData['order_id'] ?? '');
        if ($orderId === '' || strlen($orderId) > self::MAX_ORDER_ID_LENGTH) {
            throw new RuntimeException('PayPal order_id must be 1-127 characters.');
        }

        $currency = strtoupper((string) ($paymentData['currency'] ?? self::DEFAULT_CURRENCY));
        if (!in_array($currency, self::SUPPORTED_CURRENCIES, true)) {
            throw new RuntimeException(
                "PayPal does not support currency {$currency}"
                . ($currency === 'IDR' ? ' (PayPal cannot receive payments in Indonesian Rupiah at all)' : '')
                . '.',
            );
        }

        $amount = (float) ($paymentData['amount'] ?? 0);
        $purchaseUnit = [
            'reference_id' => $orderId,
            'custom_id' => $orderId,
            'amount' => ['currency_code' => $currency, 'value' => $this->formatAmount($amount, $currency)],
        ];

        $payload = ['intent' => 'CAPTURE', 'purchase_units' => [$purchaseUnit]];
        $returnUrl = $paymentData['return_url'] ?? null;
        $cancelUrl = $paymentData['cancel_url'] ?? null;
        if (is_string($returnUrl) && $returnUrl !== '') {
            $payload['application_context'] = [
                'return_url' => $returnUrl,
                'cancel_url' => is_string($cancelUrl) && $cancelUrl !== '' ? $cancelUrl : $returnUrl,
                'user_action' => 'PAY_NOW',
            ];
        }

        $response = ($this->httpRequester)('POST', $this->baseUrl() . '/v2/checkout/orders', $this->authHeaders(true), json_encode($payload));
        $decoded = $this->decodeOrFail($response, 'create-order');
        $approveUrl = '';
        foreach ((array) ($decoded['links'] ?? []) as $link) {
            if (is_array($link) && ($link['rel'] ?? '') === 'approve') {
                $approveUrl = (string) ($link['href'] ?? '');
                break;
            }
        }

        return [
            'gateway' => 'PAYPAL',
            'status' => 'PENDING',
            'payment_id' => $orderId,
            'external_transaction_id' => (string) ($decoded['id'] ?? ''),
            'order_id' => $orderId,
            'amount' => $amount,
            'currency' => $currency,
            'payment_url' => $approveUrl,
            'payment_method' => 'PAYPAL',
            'expired_at' => gmdate('c', time() + self::ORDER_EXPIRY_SECONDS),
        ];
    }

    public function getPaymentStatus(string $externalTransactionId): array
    {
        $order = $this->fetchOrder($externalTransactionId);
        $status = (string) ($order['status'] ?? '');

        if ($status === 'APPROVED') {
            $order = $this->captureOrder($externalTransactionId);
            $status = (string) ($order['status'] ?? $status);
        }

        $purchaseUnit = (array) ($order['purchase_units'][0] ?? []);
        $amount = (array) ($purchaseUnit['amount'] ?? []);

        return [
            'gateway' => 'PAYPAL',
            'external_transaction_id' => $externalTransactionId,
            'status' => self::mapOrderStatus($status),
            'amount' => (float) ($amount['value'] ?? 0),
            'currency' => (string) ($amount['currency_code'] ?? self::DEFAULT_CURRENCY),
        ];
    }

    public function cancelPayment(string $externalTransactionId): array
    {
        throw new RuntimeException(
            'PayPal has no cancel-order endpoint for a CAPTURE-intent order; '
            . 'an unapproved order simply expires (~3 hours after creation).',
        );
    }

    public function refundPayment(string $externalTransactionId, float $amount): array
    {
        $order = $this->fetchOrder($externalTransactionId);
        $purchaseUnit = (array) ($order['purchase_units'][0] ?? []);
        $captures = (array) ($purchaseUnit['payments']['captures'] ?? []);
        $captureId = isset($captures[0]['id']) ? (string) $captures[0]['id'] : '';
        if ($captureId === '') {
            throw new RuntimeException('PayPal refund requires a completed capture; none was found for this order.');
        }
        $currency = (string) ($purchaseUnit['amount']['currency_code'] ?? self::DEFAULT_CURRENCY);

        $response = ($this->httpRequester)(
            'POST',
            $this->baseUrl() . '/v2/payments/captures/' . rawurlencode($captureId) . '/refund',
            $this->authHeaders(true),
            json_encode(['amount' => ['currency_code' => $currency, 'value' => $this->formatAmount($amount, $currency)]]),
        );
        $this->decodeOrFail($response, 'refund');

        return [
            'gateway' => 'PAYPAL',
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

        $authAlgo = (string) ($headers['paypal-auth-algo'] ?? '');
        $certUrl = (string) ($headers['paypal-cert-url'] ?? '');
        $transmissionId = (string) ($headers['paypal-transmission-id'] ?? '');
        $transmissionSig = (string) ($headers['paypal-transmission-sig'] ?? '');
        $transmissionTime = (string) ($headers['paypal-transmission-time'] ?? '');
        if ($authAlgo === '' || $certUrl === '' || $transmissionId === '' || $transmissionSig === '' || $transmissionTime === '') {
            return false;
        }

        $response = ($this->httpRequester)('POST', $this->baseUrl() . '/v1/notifications/verify-webhook-signature', $this->authHeaders(true), json_encode([
            'auth_algo' => $authAlgo,
            'cert_url' => $certUrl,
            'transmission_id' => $transmissionId,
            'transmission_sig' => $transmissionSig,
            'transmission_time' => $transmissionTime,
            'webhook_id' => $this->webhookId(),
            'webhook_event' => $decoded,
        ]));
        $status = (int) ($response['status'] ?? 0);
        $result = json_decode((string) ($response['body'] ?? ''), true);

        return $status >= 200 && $status < 300 && is_array($result) && ($result['verification_status'] ?? '') === 'SUCCESS';
    }

    public function handleWebhook(array $headers, string $payload): array
    {
        $decoded = json_decode($payload, true);
        $data = is_array($decoded) ? $decoded : [];
        $eventType = (string) ($data['event_type'] ?? '');
        $resource = (array) ($data['resource'] ?? []);

        $orderId = str_starts_with($eventType, 'CHECKOUT.ORDER.')
            ? (string) ($resource['id'] ?? '')
            : (string) ($resource['supplementary_data']['related_ids']['order_id'] ?? '');

        return [
            'event_id' => isset($data['id']) ? (string) $data['id'] : hash('sha256', $payload),
            'gateway' => 'PAYPAL',
            'order_id' => $orderId,
            'external_transaction_id' => $orderId,
            'status' => self::mapEventType($eventType),
            'event_type' => $eventType,
            'occurred_at' => isset($data['create_time']) ? (string) $data['create_time'] : gmdate('c'),
        ];
    }

    private static function mapOrderStatus(string $status): string
    {
        return match ($status) {
            'COMPLETED' => 'PAID',
            'VOIDED' => 'CANCELLED',
            'CREATED', 'SAVED', 'APPROVED', 'PAYER_ACTION_REQUIRED' => 'PENDING',
            default => 'UNKNOWN',
        };
    }

    private static function mapEventType(string $eventType): string
    {
        return match ($eventType) {
            'PAYMENT.CAPTURE.COMPLETED' => 'PAID',
            'PAYMENT.CAPTURE.DENIED' => 'FAILED',
            'PAYMENT.CAPTURE.REFUNDED' => 'REFUNDED',
            'PAYMENT.CAPTURE.PENDING', 'CHECKOUT.ORDER.APPROVED', 'CHECKOUT.ORDER.COMPLETED' => 'PENDING',
            default => 'UNKNOWN',
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function fetchOrder(string $orderId): array
    {
        $response = ($this->httpRequester)('GET', $this->baseUrl() . '/v2/checkout/orders/' . rawurlencode($orderId), $this->authHeaders(false), null);

        return $this->decodeOrFail($response, 'get-order');
    }

    /**
     * @return array<string, mixed>
     */
    private function captureOrder(string $orderId): array
    {
        $response = ($this->httpRequester)('POST', $this->baseUrl() . '/v2/checkout/orders/' . rawurlencode($orderId) . '/capture', $this->authHeaders(true), '{}');

        return $this->decodeOrFail($response, 'capture-order');
    }

    private function formatAmount(float $amount, string $currency): string
    {
        return in_array($currency, self::ZERO_DECIMAL_CURRENCIES, true)
            ? (string) (int) round($amount)
            : number_format($amount, 2, '.', '');
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeOrFail(array $response, string $operation): array
    {
        $status = (int) ($response['status'] ?? 0);
        $decoded = json_decode((string) ($response['body'] ?? ''), true);
        if ($status < 200 || $status >= 300 || !is_array($decoded)) {
            $message = is_array($decoded) ? ($decoded['message'] ?? ($decoded['details'][0]['description'] ?? null)) : null;
            throw new RuntimeException("PayPal {$operation} failed: " . ($message ?? "HTTP {$status}"));
        }

        return $decoded;
    }

    /**
     * @return array<string, string>
     */
    private function authHeaders(bool $withContentType): array
    {
        $headers = [
            'Authorization' => 'Bearer ' . $this->accessToken(),
            'Accept' => 'application/json',
        ];
        if ($withContentType) {
            $headers['Content-Type'] = 'application/json';
        }

        return $headers;
    }

    private function accessToken(): string
    {
        $response = ($this->httpRequester)('POST', $this->baseUrl() . '/v1/oauth2/token', [
            'Authorization' => 'Basic ' . base64_encode($this->clientId() . ':' . $this->clientSecret()),
            'Content-Type' => 'application/x-www-form-urlencoded',
            'Accept' => 'application/json',
        ], 'grant_type=client_credentials');

        $decoded = $this->decodeOrFail($response, 'oauth2-token');
        $token = (string) ($decoded['access_token'] ?? '');
        if ($token === '') {
            throw new RuntimeException('PayPal oauth2-token response did not include an access_token.');
        }

        return $token;
    }

    private function clientId(): string
    {
        $value = (string) ($this->configuration['client_id'] ?? Config::get('PAYPAL_CLIENT_ID', ''));
        if ($value === '') {
            throw new RuntimeException('PAYPAL_CLIENT_ID is not configured.');
        }

        return $value;
    }

    private function clientSecret(): string
    {
        $value = (string) ($this->configuration['client_secret'] ?? Config::get('PAYPAL_CLIENT_SECRET', ''));
        if ($value === '') {
            throw new RuntimeException('PAYPAL_CLIENT_SECRET is not configured.');
        }

        return $value;
    }

    private function webhookId(): string
    {
        $value = (string) ($this->configuration['webhook_id'] ?? Config::get('PAYPAL_WEBHOOK_ID', ''));
        if ($value === '') {
            throw new RuntimeException('PAYPAL_WEBHOOK_ID is not configured.');
        }

        return $value;
    }

    private function isProduction(): bool
    {
        $env = strtoupper((string) ($this->configuration['environment'] ?? Config::get('PAYPAL_ENVIRONMENT', 'SANDBOX')));

        return $env === 'PRODUCTION' || $env === 'LIVE';
    }

    private function baseUrl(): string
    {
        $default = $this->isProduction() ? self::PRODUCTION_BASE_URL : self::SANDBOX_BASE_URL;

        return rtrim((string) ($this->configuration['api_url'] ?? Config::get('PAYPAL_API_URL', $default)), '/');
    }
}
