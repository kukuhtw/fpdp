<?php

declare(strict_types=1);

namespace App\Services\Marketplace;

use App\Core\Config;
use App\Core\Exceptions\ForbiddenException;
use App\Core\Exceptions\NotFoundException;
use App\Core\Exceptions\ValidationException;
use App\Repositories\OrderItemRepository;
use App\Repositories\OrderRepository;
use App\Repositories\ProductRepository;
use App\Repositories\RemoteNodeRepository;
use App\Services\Federation\SignedRequestVerifier;
use App\Services\Security\AuditService;

/**
 * Seller side of node-to-node orders (FEDERATION-CONCEPT §11b).
 *
 * The owner of another FPDP node orders from this shop from their own
 * dashboard: their node POSTs an `fpdp:OrderRequest`, HTTP-signed as their
 * actor, and later reads the order's status with a signed GET. The actor is
 * the buyer's identity here — no Google sign-in — and only that actor can
 * read the order. Payment still happens on this node's own gateway; the
 * response carries the payment page the buyer is sent to.
 *
 * Digital products are delivered as short-lived signed links (15 minutes,
 * HMAC with APP_KEY) handed out in the signed status response only.
 */
final class FederatedOrderService
{
    public const REQUEST_TYPE = 'fpdp:OrderRequest';
    private const DOWNLOAD_TTL_SECONDS = 900;
    private const PAID_STATUSES = ['CONFIRMED', 'PROCESSING', 'COMPLETED'];

    public function __construct(
        private readonly MarketplaceService $marketplace,
        private readonly OrderRepository $orders,
        private readonly OrderItemRepository $orderItems,
        private readonly ProductRepository $products,
        private readonly RemoteNodeRepository $remoteNodes,
        private readonly SignedRequestVerifier $verifier,
        private readonly ?ProductDigitalAssetService $assets = null,
        private readonly ?AuditService $audit = null,
    ) {
    }

    /**
     * POST /api/v1/federation/orders
     *
     * @param array<string, mixed> $localNode the selling node (id, domain)
     * @param array<string, string> $headers
     * @return array{order: array<string, mixed>, duplicate: bool}
     */
    public function receive(array $localNode, array $headers, string $rawBody, string $path): array
    {
        $request = json_decode($rawBody, true);
        if (!is_array($request)) {
            throw new ValidationException([['field' => '_', 'reason' => 'invalid_json']]);
        }

        $actorUri = (string) ($request['actor'] ?? '');
        $actorHost = strtolower((string) parse_url($actorUri, PHP_URL_HOST));
        $reference = strtolower((string) ($request['clientReference'] ?? ''));
        $errors = [];
        if (($request['type'] ?? null) !== self::REQUEST_TYPE) {
            $errors[] = ['field' => 'type', 'reason' => 'invalid_value'];
        }
        if (preg_match('~^https://[^\s/?#]+/~i', $actorUri) !== 1 || strlen($actorUri) > 512) {
            $errors[] = ['field' => 'actor', 'reason' => 'invalid_actor_uri'];
        }
        if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $reference) !== 1) {
            $errors[] = ['field' => 'clientReference', 'reason' => 'invalid_format'];
        }
        $returnUrl = self::sameHostHttpsUrl($request['returnUrl'] ?? null, $actorHost);
        if (($request['returnUrl'] ?? null) !== null && $returnUrl === null) {
            $errors[] = ['field' => 'returnUrl', 'reason' => 'must_be_https_on_actor_host'];
        }
        if ($errors !== []) {
            throw new ValidationException($errors);
        }
        if ($actorHost === strtolower((string) $localNode['domain'])) {
            throw new ForbiddenException('A node cannot order from itself.');
        }
        if (in_array($actorHost, array_map('strtolower', $this->remoteNodes->findBlockedDomains()), true)) {
            throw new ForbiddenException('Sender domain is blocked.');
        }

        $this->verifier->verify('POST', $path, $headers, $rawBody, $actorUri);

        $items = [];
        foreach (is_array($request['items'] ?? null) ? $request['items'] : [] as $item) {
            $items[] = ['product_id' => (string) ($item['productId'] ?? ''), 'quantity' => min(100, max(1, (int) ($item['quantity'] ?? 1)))];
        }
        $buyer = is_array($request['buyer'] ?? null) ? $request['buyer'] : [];
        $result = $this->marketplace->placeRemoteOrder((int) $localNode['id'], $actorUri, $reference, [
            'items' => $items,
            'buyer_name' => (string) ($buyer['name'] ?? ''),
            'buyer_email' => (string) ($buyer['email'] ?? ''),
            'shipping_address' => (string) ($request['shippingAddress'] ?? ''),
            'notes' => isset($request['notes']) ? (string) $request['notes'] : null,
        ], $returnUrl, $returnUrl);

        if (!$result['duplicate']) {
            $this->audit?->record(['user' => null, 'node' => $localNode], 'order.federated_received', 'order', (string) $result['order']['public_id'], [
                'buyer_actor' => $actorUri,
                'total_amount' => (string) $result['order']['total_amount'],
                'currency' => (string) $result['order']['currency'],
            ]);
        }

        $presented = $this->present($result['order'], (string) $localNode['domain']);
        if ($result['payment'] !== null) {
            $presented['payment'] = [
                'status' => (string) ($result['payment']['status'] ?? 'PENDING'),
                'payment_url' => self::httpsUrlOrNull($result['payment']['payment_url'] ?? null),
                'instructions' => is_string($result['payment']['instructions'] ?? null) ? mb_substr($result['payment']['instructions'], 0, 2000) : null,
            ];
        }

        return ['order' => $presented, 'duplicate' => $result['duplicate']];
    }

    /**
     * GET /api/v1/federation/orders/{id} — signed by the actor who placed it.
     *
     * @param array<string, mixed> $localNode
     * @param array<string, string> $headers
     * @return array<string, mixed>
     */
    public function status(array $localNode, string $orderPublicId, array $headers, string $path): array
    {
        $order = $this->orders->findByPublicId($orderPublicId);
        if ($order === null || (int) $order['node_id'] !== (int) $localNode['id'] || ($order['remote_actor_uri'] ?? null) === null) {
            throw new NotFoundException('Order not found.');
        }
        // Only the actor that placed the order may read it (buyer data inside).
        $this->verifier->verify('GET', $path, $headers, '', (string) $order['remote_actor_uri']);

        return $this->present($order, (string) $localNode['domain']);
    }

    /**
     * GET /api/v1/federation/orders/{id}/downloads/{productId}/{kind}?expires=&token=
     *
     * @return array{content: string, content_type: string, filename: string}
     */
    public function download(string $orderPublicId, string $productPublicId, string $kind, string $expires, string $token): array
    {
        $expected = self::downloadToken($orderPublicId, $productPublicId, strtoupper($kind), $expires);
        if (!ctype_digit($expires) || (int) $expires < time() || !hash_equals($expected, $token)) {
            throw new ForbiddenException('This download link has expired. Refresh the order to get a new one.');
        }

        $order = $this->orders->findByPublicId($orderPublicId);
        if ($order === null || !in_array((string) $order['status'], self::PAID_STATUSES, true)) {
            throw new ForbiddenException('This order is not paid.');
        }
        $product = null;
        foreach ($this->orderItems->findByOrderId((int) $order['id']) as $item) {
            if ((string) $item['product_public_id'] === $productPublicId) {
                $product = $this->products->findByPublicId($productPublicId);
            }
        }
        if ($product === null || (string) ($product['product_type'] ?? '') !== 'DIGITAL' || $this->assets === null) {
            throw new NotFoundException('Download not found.');
        }

        return $this->assets->readForDownload((int) $product['id'], $kind);
    }

    /**
     * What the buyer's node sees: no payment references, no internal ids.
     *
     * @param array<string, mixed> $order
     * @return array<string, mixed>
     */
    private function present(array $order, string $domain): array
    {
        $paid = in_array((string) $order['status'], self::PAID_STATUSES, true);
        $items = [];
        $downloads = [];
        $expires = (string) (time() + self::DOWNLOAD_TTL_SECONDS);
        foreach ($this->orderItems->findByOrderId((int) $order['id']) as $item) {
            $snapshot = is_string($item['product_snapshot'] ?? null) ? (json_decode($item['product_snapshot'], true) ?: []) : [];
            $productId = (string) $item['product_public_id'];
            $product = $this->products->findByPublicId($productId);
            $type = (string) ($product['product_type'] ?? 'PHYSICAL');
            $items[] = [
                'product_id' => $productId,
                'title' => (string) ($snapshot['title'] ?? $product['title'] ?? ''),
                'product_type' => $type,
                'quantity' => (int) $item['quantity'],
                'unit_price' => number_format((float) $item['unit_price'], 2, '.', ''),
                'subtotal' => number_format((float) $item['subtotal'], 2, '.', ''),
            ];

            if (!$paid || $type !== 'DIGITAL' || $product === null) {
                continue;
            }
            if (is_string($product['digital_asset_url'] ?? null) && preg_match('#^https://#i', $product['digital_asset_url']) === 1) {
                $downloads[] = ['product_id' => $productId, 'title' => $items[array_key_last($items)]['title'], 'label' => 'link', 'url' => $product['digital_asset_url'], 'expires_at' => null];
            }
            foreach ($this->assets?->listForProduct((int) $product['id']) ?? [] as $asset) {
                $kind = (string) $asset['kind'];
                $downloads[] = [
                    'product_id' => $productId,
                    'title' => $items[array_key_last($items)]['title'],
                    'label' => (string) ($asset['original_filename'] ?? strtolower($kind)),
                    'url' => sprintf(
                        'https://%s/api/v1/federation/orders/%s/downloads/%s/%s?expires=%s&token=%s',
                        $domain,
                        rawurlencode((string) $order['public_id']),
                        rawurlencode($productId),
                        rawurlencode(strtolower($kind)),
                        $expires,
                        self::downloadToken((string) $order['public_id'], $productId, strtoupper($kind), $expires),
                    ),
                    'expires_at' => gmdate('c', (int) $expires),
                ];
            }
        }

        return [
            'id' => (string) $order['public_id'],
            'status' => (string) $order['status'],
            'total_amount' => number_format((float) $order['total_amount'], 2, '.', ''),
            'currency' => (string) $order['currency'],
            'items' => $items,
            'downloads' => $downloads,
            'status_url' => "https://{$domain}/api/v1/federation/orders/" . rawurlencode((string) $order['public_id']),
            'created_at' => (string) $order['created_at'],
            'updated_at' => (string) ($order['updated_at'] ?? $order['created_at']),
        ];
    }

    private static function downloadToken(string $orderId, string $productId, string $kind, string $expires): string
    {
        return hash_hmac('sha256', "fpdp-order-download|{$orderId}|{$productId}|{$kind}|{$expires}", (string) Config::get('APP_KEY', ''));
    }

    private static function sameHostHttpsUrl(mixed $url, string $host): ?string
    {
        if (!is_string($url) || strlen($url) > 2048 || preg_match('#^https://[^\s"\'<>]+$#i', $url) !== 1) {
            return null;
        }

        return strtolower((string) parse_url($url, PHP_URL_HOST)) === $host ? $url : null;
    }

    private static function httpsUrlOrNull(mixed $url): ?string
    {
        return is_string($url) && preg_match('#^https://[^\s"\'<>]+$#i', $url) === 1 ? $url : null;
    }
}
