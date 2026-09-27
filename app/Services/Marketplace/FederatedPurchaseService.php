<?php

declare(strict_types=1);

namespace App\Services\Marketplace;

use App\Core\Exceptions\ConflictException;
use App\Core\Exceptions\NotFoundException;
use App\Core\Exceptions\ValidationException;
use App\Core\Http\HttpClient;
use App\Core\Uuid;
use App\Repositories\FederatedPostRepository;
use App\Repositories\FederatedPurchaseRepository;
use App\Services\Federation\SignedRequestSigner;
use App\Services\Security\AuditService;

/**
 * Buyer side of node-to-node orders (FEDERATION-CONCEPT §11b): the owner
 * orders a product from another FPDP node they follow, from their own
 * dashboard.
 *
 * This node sends the seller an `fpdp:OrderRequest` HTTP-signed as the
 * owner's actor, keeps a copy of the purchase, and sends the owner to the
 * seller's payment page. Status (and, for digital products, download links)
 * is read back with signed GETs: when the owner opens the page, on refresh,
 * and from the cron (scripts/sync-purchases.php).
 *
 * A request whose outcome is unknown (network error, 5xx) stays SUBMITTING
 * and is re-sent with the same client reference, which the seller treats as
 * the same order — never a double order.
 */
final class FederatedPurchaseService
{
    private const SELLER_STATUSES = ['PENDING', 'CONFIRMED', 'PROCESSING', 'COMPLETED', 'CANCELLED', 'REFUNDED'];
    private const UUID = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/';

    public function __construct(
        private readonly FederatedPostRepository $posts,
        private readonly FederatedPurchaseRepository $purchases,
        private readonly SignedRequestSigner $signer,
        private readonly HttpClient $http,
        private readonly ?AuditService $audit = null,
    ) {
    }

    /**
     * Products from followed accounts, marking which can be ordered here.
     *
     * @param array<string, mixed> $context
     * @return array<int, array<string, mixed>>
     */
    public function shop(array $context): array
    {
        $profileId = (int) ($context['profile']['id'] ?? 0);

        return array_map([self::class, 'presentProduct'], $this->posts->listProductsForProfile($profileId, 100));
    }

    /**
     * @param array<string, mixed> $context
     * @return array<int, array<string, mixed>>
     */
    public function list(array $context): array
    {
        return array_map([self::class, 'present'], $this->purchases->listByNode((int) $context['node']['id']));
    }

    /**
     * @param array<string, mixed> $context
     * @param array<string, mixed> $input post_id, quantity, buyer_name, buyer_email, shipping_address, notes
     * @return array<string, mixed>
     */
    public function place(array $context, array $input): array
    {
        $profileId = (int) ($context['profile']['id'] ?? 0);
        $postId = (string) ($input['post_id'] ?? '');
        $row = $postId !== '' ? ($this->posts->listProductsForProfile($profileId, 1, $postId)[0] ?? null) : null;
        if ($row === null) {
            throw new NotFoundException('Product not found in the accounts you follow.');
        }
        $product = $row['product_data'];
        if (!isset($product['product_id'], $product['order_endpoint'])) {
            throw new ConflictException('This seller only takes orders through its own checkout page.');
        }
        $sellerHost = strtolower((string) parse_url((string) $row['actor_uri'], PHP_URL_HOST));
        if (strtolower((string) parse_url((string) $product['order_endpoint'], PHP_URL_HOST)) !== $sellerHost) {
            throw new ConflictException('This product has an invalid order endpoint.');
        }

        $quantity = filter_var($input['quantity'] ?? 1, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 100]]);
        $name = trim((string) ($input['buyer_name'] ?? ''));
        $email = strtolower(trim((string) ($input['buyer_email'] ?? $context['user']['email'] ?? '')));
        $address = trim((string) ($input['shipping_address'] ?? ''));
        $notes = trim((string) ($input['notes'] ?? ''));
        $errors = [];
        if ($quantity === false) {
            $errors[] = ['field' => 'quantity', 'reason' => 'invalid_value'];
        }
        if ($name === '' || mb_strlen($name) > 128) {
            $errors[] = ['field' => 'buyer_name', 'reason' => 'invalid_length'];
        }
        if ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            $errors[] = ['field' => 'buyer_email', 'reason' => 'invalid_format'];
        }
        if (($product['product_type'] ?? 'PHYSICAL') === 'PHYSICAL' && $address === '') {
            $errors[] = ['field' => 'shipping_address', 'reason' => 'required_for_physical_items'];
        }
        if (mb_strlen($address) > 1000 || mb_strlen($notes) > 1000) {
            $errors[] = ['field' => mb_strlen($address) > 1000 ? 'shipping_address' : 'notes', 'reason' => 'invalid_length'];
        }
        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        $unit = (string) $product['price'];
        $purchase = $this->purchases->create([
            'public_id' => Uuid::v4(),
            'node_id' => (int) $context['node']['id'],
            'seller_domain' => $sellerHost,
            'seller_actor_uri' => (string) $row['actor_uri'],
            'order_endpoint' => (string) $product['order_endpoint'],
            'product_object_uri' => (string) $row['object_uri'],
            'items' => [[
                'product_id' => (string) $product['product_id'],
                'title' => (string) ($row['title'] ?? ''),
                'product_type' => (string) ($product['product_type'] ?? 'PHYSICAL'),
                'quantity' => $quantity,
                'unit_price' => $unit,
                'subtotal' => number_format((float) $unit * $quantity, 2, '.', ''),
            ]],
            'total_amount' => number_format((float) $unit * $quantity, 2, '.', ''),
            'currency' => (string) $product['currency'],
            'buyer_name' => $name,
            'buyer_email' => $email,
            'shipping_address' => $address !== '' ? $address : null,
            'notes' => $notes !== '' ? $notes : null,
        ]);

        $purchase = $this->submit($purchase, (string) $context['node']['domain']);
        $this->audit?->record($context, 'purchase.federated_placed', 'federated_purchase', (string) $purchase['public_id'], [
            'seller' => $sellerHost,
            'status' => (string) $purchase['status'],
            'total_amount' => (string) $purchase['total_amount'],
            'currency' => (string) $purchase['currency'],
        ]);

        return self::present($purchase);
    }

    /**
     * Re-reads one purchase from the seller (or re-sends it if it never
     * got through).
     *
     * @param array<string, mixed> $context
     * @return array<string, mixed>
     */
    public function refresh(array $context, string $purchaseId): array
    {
        $purchase = $this->purchases->findByPublicId($purchaseId);
        if ($purchase === null || (int) $purchase['node_id'] !== (int) $context['node']['id']) {
            throw new NotFoundException('Purchase not found.');
        }

        return self::present($this->sync($purchase, (string) $context['node']['domain']));
    }

    /**
     * Cron: refresh open purchases not synced in the last $minutes.
     *
     * @return array{synced: int, failed: int}
     */
    public function syncOpen(string $localDomain, int $minutes = 10): array
    {
        $synced = 0;
        $failed = 0;
        foreach ($this->purchases->listOpenForSync(date('Y-m-d H:i:s', time() - $minutes * 60)) as $purchase) {
            $after = $this->sync($purchase, $localDomain);
            ($after['last_error'] ?? null) === null ? $synced++ : $failed++;
        }

        return ['synced' => $synced, 'failed' => $failed];
    }

    /**
     * @param array<string, mixed> $purchase
     * @return array<string, mixed>
     */
    private function sync(array $purchase, string $localDomain): array
    {
        if ((string) $purchase['status'] === 'SUBMITTING' || ($purchase['remote_order_id'] ?? null) === null) {
            try {
                return $this->submit($purchase, $localDomain);
            } catch (ConflictException) {
                // Refused by the seller on retry: submit() already removed it.
                return $purchase + ['last_error' => 'refused'];
            }
        }

        $url = rtrim((string) $purchase['order_endpoint'], '/') . '/' . rawurlencode((string) $purchase['remote_order_id']);
        try {
            $response = $this->http->request('GET', $url, $this->signer->headers((int) $purchase['node_id'], 'GET', $url) + ['Accept' => 'application/json'], null, 15, 512 * 1024);
        } catch (\Throwable $e) {
            return $this->purchases->update((string) $purchase['public_id'], ['last_error' => self::shorten('Could not reach the seller: ' . $e->getMessage())]);
        }

        $payload = json_decode($response['body'], true);
        if ($response['status'] >= 200 && $response['status'] < 300 && is_array($payload['data'] ?? null)) {
            return $this->applyRemoteOrder($purchase, $payload['data'], null);
        }

        $message = is_string($payload['error']['message'] ?? null) ? $payload['error']['message'] : "HTTP {$response['status']}";

        return $this->purchases->update((string) $purchase['public_id'], ['last_error' => self::shorten("The seller answered: {$message}")]);
    }

    /**
     * Sends (or re-sends, with the same client reference) the order request.
     *
     * @param array<string, mixed> $purchase
     * @return array<string, mixed>
     */
    private function submit(array $purchase, string $localDomain): array
    {
        $nodeId = (int) $purchase['node_id'];
        $body = json_encode([
            '@context' => ['https://www.w3.org/ns/activitystreams', ['fpdp' => 'https://github.com/kukuhtw/fpdp/ns#']],
            'type' => FederatedOrderService::REQUEST_TYPE,
            'actor' => $this->signer->actorUri($nodeId),
            'clientReference' => (string) $purchase['public_id'],
            'items' => array_map(static fn (array $item): array => ['productId' => (string) $item['product_id'], 'quantity' => (int) $item['quantity']], $purchase['items']),
            'buyer' => ['name' => (string) $purchase['buyer_name'], 'email' => (string) $purchase['buyer_email']],
            'shippingAddress' => $purchase['shipping_address'],
            'notes' => $purchase['notes'],
            'returnUrl' => "https://{$localDomain}/dashboard/purchases?ref=" . rawurlencode((string) $purchase['public_id']),
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        $url = (string) $purchase['order_endpoint'];
        try {
            $response = $this->http->request('POST', $url, $this->signer->headers($nodeId, 'POST', $url, $body) + [
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
            ], $body, 20, 512 * 1024);
        } catch (\Throwable $e) {
            // Unknown outcome: keep it and re-send later with the same reference.
            return $this->purchases->update((string) $purchase['public_id'], [
                'last_error' => self::shorten('Could not reach the seller: ' . $e->getMessage()),
                'last_synced_at' => date('Y-m-d H:i:s'),
            ]);
        }

        $payload = json_decode($response['body'], true);
        if ($response['status'] >= 200 && $response['status'] < 300 && is_array($payload['data']['order'] ?? null)) {
            return $this->applyRemoteOrder($purchase, $payload['data']['order'], $payload['data']['order']['payment'] ?? null);
        }

        $message = is_string($payload['error']['message'] ?? null) ? $payload['error']['message'] : "HTTP {$response['status']}";
        if ($response['status'] >= 400 && $response['status'] < 500 && !in_array($response['status'], [408, 429], true)) {
            // Refused for good (validation, not available, signature): nothing
            // was created on the seller's side, so drop our copy too.
            $this->purchases->delete((string) $purchase['public_id']);
            $details = is_array($payload['error']['details'] ?? null) ? ' (' . implode(', ', array_map(
                static fn ($d): string => is_array($d) ? ((string) ($d['field'] ?? '') . ': ' . (string) ($d['reason'] ?? '')) : '',
                $payload['error']['details'],
            )) . ')' : '';
            throw new ConflictException(self::shorten("The seller refused the order: {$message}{$details}"));
        }

        return $this->purchases->update((string) $purchase['public_id'], [
            'last_error' => self::shorten("The seller answered: {$message}"),
            'last_synced_at' => date('Y-m-d H:i:s'),
        ]);
    }

    /**
     * Takes the seller's view of the order, after checking its shape: the
     * seller's figures (prices, total, status) are authoritative.
     *
     * @param array<string, mixed> $purchase
     * @param array<string, mixed> $order
     * @param mixed $payment
     * @return array<string, mixed>
     */
    private function applyRemoteOrder(array $purchase, array $order, mixed $payment): array
    {
        $orderId = strtolower((string) ($order['id'] ?? ''));
        $status = (string) ($order['status'] ?? '');
        if (preg_match(self::UUID, $orderId) !== 1 || !in_array($status, self::SELLER_STATUSES, true)
            || !is_numeric($order['total_amount'] ?? null) || preg_match('/^[A-Z]{3}$/', (string) ($order['currency'] ?? '')) !== 1
            || (($purchase['remote_order_id'] ?? null) !== null && $purchase['remote_order_id'] !== $orderId)) {
            return $this->purchases->update((string) $purchase['public_id'], [
                'last_error' => 'The seller sent an order this node could not read.',
                'last_synced_at' => date('Y-m-d H:i:s'),
            ]);
        }

        $items = [];
        foreach (is_array($order['items'] ?? null) ? array_slice($order['items'], 0, 20) : [] as $item) {
            if (!is_array($item)) {
                continue;
            }
            $items[] = [
                'product_id' => (string) ($item['product_id'] ?? ''),
                'title' => mb_substr((string) ($item['title'] ?? ''), 0, 255),
                'product_type' => in_array($item['product_type'] ?? null, ['PHYSICAL', 'DIGITAL', 'SERVICE'], true) ? $item['product_type'] : 'PHYSICAL',
                'quantity' => max(1, (int) ($item['quantity'] ?? 1)),
                'unit_price' => number_format((float) ($item['unit_price'] ?? 0), 2, '.', ''),
                'subtotal' => number_format((float) ($item['subtotal'] ?? 0), 2, '.', ''),
            ];
        }
        $downloads = [];
        foreach (is_array($order['downloads'] ?? null) ? array_slice($order['downloads'], 0, 20) : [] as $download) {
            if (is_array($download) && self::isHttpsUrl($download['url'] ?? null)) {
                $downloads[] = [
                    'title' => mb_substr((string) ($download['title'] ?? ''), 0, 255),
                    'label' => mb_substr((string) ($download['label'] ?? ''), 0, 255),
                    'url' => (string) $download['url'],
                    'expires_at' => is_string($download['expires_at'] ?? null) ? $download['expires_at'] : null,
                ];
            }
        }

        $fields = [
            'remote_order_id' => $orderId,
            'status' => $status,
            'total_amount' => number_format((float) $order['total_amount'], 2, '.', ''),
            'currency' => (string) $order['currency'],
            'downloads' => $downloads,
            'last_error' => null,
            'last_synced_at' => date('Y-m-d H:i:s'),
        ];
        if ($items !== []) {
            $fields['items'] = $items;
        }
        if (is_array($payment) && self::isHttpsUrl($payment['payment_url'] ?? null)) {
            $fields['payment_url'] = (string) $payment['payment_url'];
        }
        if ($status !== 'PENDING') {
            $fields['payment_url'] = null;
        }

        return $this->purchases->update((string) $purchase['public_id'], $fields);
    }

    /**
     * @param array<string, mixed> $purchase
     * @return array<string, mixed>
     */
    private static function present(array $purchase): array
    {
        return [
            'id' => (string) $purchase['public_id'],
            'seller_domain' => (string) $purchase['seller_domain'],
            'seller_actor_uri' => (string) $purchase['seller_actor_uri'],
            'remote_order_id' => $purchase['remote_order_id'] ?? null,
            'status' => (string) $purchase['status'],
            'items' => $purchase['items'],
            'total_amount' => (string) $purchase['total_amount'],
            'currency' => (string) $purchase['currency'],
            'payment_url' => (string) $purchase['status'] === 'PENDING' ? ($purchase['payment_url'] ?? null) : null,
            'downloads' => in_array((string) $purchase['status'], ['CONFIRMED', 'PROCESSING', 'COMPLETED'], true) ? $purchase['downloads'] : [],
            'buyer_name' => $purchase['buyer_name'] ?? null,
            'buyer_email' => $purchase['buyer_email'] ?? null,
            'shipping_address' => $purchase['shipping_address'] ?? null,
            'notes' => $purchase['notes'] ?? null,
            'last_error' => $purchase['last_error'] ?? null,
            'last_synced_at' => $purchase['last_synced_at'] ?? null,
            'created_at' => (string) $purchase['created_at'],
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private static function presentProduct(array $row): array
    {
        $product = $row['product_data'];
        $image = null;
        foreach ($row['attachments'] as $attachment) {
            if (is_array($attachment) && self::isHttpsUrl($attachment['url'] ?? null) && ($attachment['media_type'] ?? 'IMAGE') === 'IMAGE') {
                $image = (string) $attachment['url'];
                break;
            }
        }

        return [
            'post_id' => (string) $row['public_id'],
            'title' => (string) ($row['title'] ?? ''),
            'excerpt' => mb_substr(trim(html_entity_decode(strip_tags((string) ($row['content'] ?? '')), ENT_QUOTES | ENT_HTML5, 'UTF-8')), 0, 280),
            'image_url' => $image,
            'price' => (string) $product['price'],
            'currency' => (string) $product['currency'],
            'product_type' => (string) $product['product_type'],
            'checkout_url' => (string) $product['checkout_url'],
            'orderable' => isset($product['product_id'], $product['order_endpoint']),
            'seller' => [
                'name' => (string) ($row['actor_display_name'] ?? ''),
                'address' => (string) ($row['federated_address'] ?? ''),
                'domain' => strtolower((string) parse_url((string) $row['actor_uri'], PHP_URL_HOST)),
            ],
            'published_at' => $row['published_at'] ?? null,
        ];
    }

    private static function isHttpsUrl(mixed $url): bool
    {
        return is_string($url) && strlen($url) <= 2048 && preg_match('#^https://[^\s"\'<>]+$#i', $url) === 1;
    }

    private static function shorten(string $message): string
    {
        return mb_substr($message, 0, 500);
    }
}
