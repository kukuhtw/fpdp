<?php

declare(strict_types=1);

namespace App\Services\Marketplace;

use App\Core\Exceptions\ConflictException;
use App\Core\Exceptions\ForbiddenException;
use App\Core\Exceptions\NotFoundException;
use App\Core\Exceptions\ValidationException;
use App\Core\Uuid;
use App\Repositories\NodeRepository;
use App\Repositories\OrderItemRepository;
use App\Repositories\OrderRepository;
use App\Repositories\ProductRepository;
use App\Services\Payment\PaymentService;

final class MarketplaceService
{
    private const PRODUCT_FIELDS = ['title', 'description', 'price', 'currency', 'product_type', 'digital_asset_url', 'digital_asset_metadata', 'status', 'visibility', 'media'];
    private const VISIBILITIES = ['PUBLIC', 'UNLISTED', 'PRIVATE'];
    private const PRODUCT_TYPES = ['PHYSICAL', 'DIGITAL', 'SERVICE'];
    private const ORDER_STATUSES = ['PENDING', 'CONFIRMED', 'PROCESSING', 'COMPLETED', 'CANCELLED', 'REFUNDED'];

    /** The only gateway that confirms a payment synchronously (local dev/tests) — see CvAccessService for the same convention. */
    private const SYNCHRONOUS_GATEWAY = 'DUMMY';

    public function __construct(
        private readonly ProductRepository $products,
        private readonly OrderRepository $orders,
        private readonly OrderItemRepository $orderItems,
        private readonly ?PaymentService $payments = null,
        private readonly ?NodeRepository $nodes = null,
    ) {
    }

    public function createProduct(int $nodeId, array $input): array
    {
        $errors = $this->validateProductInput($input, false);
        if ($errors !== []) throw new ValidationException($errors);
        return $this->products->create(
            Uuid::v4(),
            $nodeId,
            $input['title'],
            $input['description'] ?? null,
            (string) ($input['price'] ?? '0'),
            $input['currency'] ?? 'IDR',
            $input['media'] ?? null,
            $input['product_type'] ?? 'PHYSICAL',
            $input['digital_asset_url'] ?? null,
            $input['digital_asset_metadata'] ?? null,
        );
    }

    public function getProduct(string $publicId): array
    {
        $product = $this->products->findByPublicId($publicId);
        if ($product === null) throw new NotFoundException('Product not found.');
        return $product;
    }
public function listProducts(int $nodeId, array $query = []): array
    {
        $limit = filter_var($query['limit'] ?? 20, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 100]]);
        $limit = $limit === false ? 20 : $limit;
        $beforeId = null;
        if (isset($query['cursor']) && $query['cursor'] !== '') {
            $decoded = base64_decode(strtr((string) $query['cursor'], '-_', '+/'), true);
            if ($decoded !== false && ctype_digit($decoded)) $beforeId = (int) $decoded;
        }
        if (isset($query['mine'])) {
            return ['items' => $this->products->listByNodeId($nodeId), 'next_cursor' => null, 'has_more' => false];
        }
        $rows = $this->products->listPublic($nodeId, $limit, $beforeId);
        $hasMore = count($rows) > $limit;
        if ($hasMore) array_pop($rows);
        $last = $rows === [] ? null : $rows[array_key_last($rows)];
        return ['items' => $rows, 'next_cursor' => $hasMore && $last !== null ? rtrim(strtr(base64_encode((string) $last['id']), '+/', '-_'), '=') : null, 'has_more' => $hasMore];
    }

    public function updateProduct(int $nodeId, string $publicId, array $input): array
    {
        $product = $this->products->findByPublicId($publicId);
        if ($product === null) throw new NotFoundException('Product not found.');
        if ((int) $product['node_id'] !== $nodeId) throw new ForbiddenException('You do not own this product.');
        $errors = $this->validateProductInput($input, true);
        if ($errors !== []) throw new ValidationException($errors);
        return $this->products->update($publicId, $input);
    }

    /**
     * Owner manually recording an order (e.g. a phone/offline sale) — not a
     * real visitor checkout. See checkout() for the visitor-facing,
     * payment-gateway-backed flow.
     */
    public function createOrder(int $nodeId, array $input): array
    {
        return $this->buildOrder($nodeId, $input, null, (string) ($input['buyer_email'] ?? '') ?: null, (string) ($input['buyer_name'] ?? '') ?: null);
    }

    /**
     * Visitor checkout: builds the order exactly like createOrder(), then
     * charges it through the node's active payment gateway — the same
     * generic PaymentService/PaymentGatewayFactory machinery CV access
     * already uses, so any built-in or plugin gateway works here too.
     *
     * @param array<string, mixed> $visitor Google-authenticated visitor row (id, email, display_name).
     * @return array{order: array<string, mixed>, payment: array<string, mixed>|null}
     */
    public function checkout(int $nodeId, array $visitor, array $input): array
    {
        $visitorId = (int) $visitor['id'];
        $order = $this->buildOrder($nodeId, $input, $visitorId, (string) $visitor['email'], $visitor['display_name'] ?? null);

        $totalAmount = (float) $order['total_amount'];
        if ($totalAmount <= 0.0) {
            $order = $this->orders->markPaid((string) $order['public_id'], 'COMPLETED', null);
            return ['order' => $order, 'payment' => null];
        }

        $gatewayCode = $this->resolveGatewayCode($nodeId);
        $payment = $this->getPayments()->createPayment($gatewayCode, [
            'order_id' => sprintf('MKT-%d-%d-%d', $order['id'], $visitorId, time()),
            'amount' => $totalAmount,
            'currency' => $order['currency'],
            'description' => 'Marketplace order ' . $order['public_id'],
            'payer_email' => $visitor['email'],
            'metadata' => ['purpose' => 'marketplace_order', 'order_public_id' => $order['public_id'], 'visitor_id' => $visitorId],
        ]);

        if ($gatewayCode === self::SYNCHRONOUS_GATEWAY) {
            $order = $this->orders->markPaid((string) $order['public_id'], 'COMPLETED', (string) ($payment['payment_id'] ?? ''));

            return ['order' => $order, 'payment' => $payment];
        }

        // Asynchronous gateway: stays PENDING until PaymentController::webhook()
        // verifies the gateway's confirmation and calls confirmPayment().
        return ['order' => $order, 'payment' => $payment];
    }

    /**
     * Called by PaymentController::fulfill() once a payment created for a
     * marketplace order has been confirmed PAID by the gateway's webhook.
     */
    public function confirmPayment(string $orderPublicId, ?string $paymentReference): void
    {
        $order = $this->orders->findByPublicId($orderPublicId);
        if ($order === null || (string) $order['status'] === 'COMPLETED') {
            return; // unknown or already-fulfilled order — nothing to do
        }

        $this->orders->markPaid($orderPublicId, 'COMPLETED', $paymentReference);
    }

    /**
     * The gateway to charge for this node's checkout: whichever one the
     * owner activated under Settings > Payments. Mirrors
     * CvAccessService::resolveGatewayCode() — no silent default, since
     * without an active gateway nothing can actually collect payment.
     */
    private function resolveGatewayCode(int $nodeId): string
    {
        $gatewayCode = $this->getNodes()->getActiveGateway($nodeId);
        if ($gatewayCode === null || $gatewayCode === '') {
            throw new ConflictException(
                'This store has no active payment gateway yet. Activate one under Settings > Payments before checking out.',
            );
        }

        return strtoupper($gatewayCode);
    }

    /**
     * @param array<string, mixed> $input
     */
    private function buildOrder(int $nodeId, array $input, ?int $visitorId, ?string $buyerEmail, ?string $buyerName): array
    {
        $errors = [];
        if (empty($input['items']) || !is_array($input['items'])) $errors[] = ['field' => 'items', 'reason' => 'required'];
        if ($errors !== []) throw new ValidationException($errors);
        $totalAmount = '0';
        $orderItems = [];
        foreach ($input['items'] as $i => $item) {
            $pid = $item['product_id'] ?? '';
            $qty = max(1, (int) ($item['quantity'] ?? 1));
            $product = $this->products->findByPublicId($pid);
            if ($product === null) { $errors[] = ['field' => "items.{$i}.product_id", 'reason' => 'not_found']; continue; }
            if ((int) $product['node_id'] !== $nodeId) { $errors[] = ['field' => "items.{$i}.product_id", 'reason' => 'not_found']; continue; }
            if ((string) ($product['status'] ?? 'ACTIVE') !== 'ACTIVE') { $errors[] = ['field' => "items.{$i}.product_id", 'reason' => 'not_available']; continue; }
            $unitPrice = $product['price'];
            $subtotal = (string) ((float) $unitPrice * $qty);
            $totalAmount = (string) ((float) $totalAmount + (float) $subtotal);
            $orderItems[] = ['product' => $product, 'quantity' => $qty, 'unit_price' => $unitPrice, 'subtotal' => $subtotal];
        }
        if ($errors !== []) throw new ValidationException($errors);
        $orderPubId = Uuid::v4();
        $order = $this->orders->create($orderPubId, $nodeId, $totalAmount, $input['currency'] ?? 'IDR', $buyerEmail, $buyerName, $input['notes'] ?? null, $visitorId);
        foreach ($orderItems as $oi) {
            $this->orderItems->create((int) $order['id'], (int) $oi['product']['id'], ['public_id' => $oi['product']['public_id'], 'title' => $oi['product']['title'], 'price' => $oi['product']['price']], $oi['quantity'], $oi['unit_price'], $oi['subtotal']);
        }
        return $this->orders->findByPublicId($orderPubId);
    }

    private function getPayments(): PaymentService
    {
        return $this->payments ??= new PaymentService();
    }

    private function getNodes(): NodeRepository
    {
        return $this->nodes ??= new NodeRepository(\App\Core\Database::connection());
    }

    public function getOrder(string $publicId): array
    {
        $order = $this->orders->findByPublicId($publicId);
        if ($order === null) throw new NotFoundException('Order not found.');
        $order['items'] = $this->orderItems->findByOrderId((int) $order['id']);
        return $order;
    }

    public function listOrders(int $nodeId, array $query = []): array
    {
        $limit = filter_var($query['limit'] ?? 20, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 100]]);
        $limit = $limit === false ? 20 : $limit;
        $beforeId = null;
        if (isset($query['cursor']) && $query['cursor'] !== '') {
            $decoded = base64_decode(strtr((string) $query['cursor'], '-_', '+/'), true);
            if ($decoded !== false && ctype_digit($decoded)) $beforeId = (int) $decoded;
        }
        $rows = $this->orders->listByNodeId($nodeId, $limit, $beforeId);
        $hasMore = count($rows) > $limit;
        if ($hasMore) array_pop($rows);
        foreach ($rows as &$row) { $row['items'] = $this->orderItems->findByOrderId((int) $row['id']); }
        unset($row);
        $last = $rows === [] ? null : $rows[array_key_last($rows)];
        return ['items' => $rows, 'next_cursor' => $hasMore && $last !== null ? rtrim(strtr(base64_encode((string) $last['id']), '+/', '-_'), '=') : null, 'has_more' => $hasMore];
    }

    public function updateOrderStatus(int $nodeId, string $publicId, string $status): array
    {
        if (!in_array($status, self::ORDER_STATUSES, true)) throw new ValidationException([['field' => 'status', 'reason' => 'invalid_value']]);
        $order = $this->orders->findByPublicId($publicId);
        if ($order === null) throw new NotFoundException('Order not found.');
        if ((int) $order['node_id'] !== $nodeId) throw new ForbiddenException('You do not own this order.');
        return $this->orders->updateStatus($publicId, $status);
    }

    /**
     * Verify that the given visitor has a COMPLETED or CONFIRMED order
     * containing the specified product. Throws ForbiddenException if no
     * qualifying order is found. Used to authorize digital download access.
     */
    public function verifyDigitalPurchase(int $visitorId, int $productId): void
    {
        $orders = $this->orderItems->findByProductId($productId);
        foreach ($orders as $item) {
            if ((int) ($item['order_visitor_id'] ?? 0) === $visitorId
                && in_array($item['order_status'], ['COMPLETED', 'CONFIRMED'], true)) {
                return; // Found a valid purchase by this visitor — authorized
            }
        }

        throw new ForbiddenException('You have not purchased this digital product.');
    }

    private function validateProductInput(array $input, bool $partial): array
    {
        $errors = [];
        $unknown = array_diff(array_keys($input), self::PRODUCT_FIELDS);
        foreach ($unknown as $field) $errors[] = ['field' => $field, 'reason' => 'unknown_field'];
        if (!$partial || array_key_exists('title', $input)) {
            $title = (string) ($input['title'] ?? '');
            if ($title === '' || mb_strlen($title) > 255) $errors[] = ['field' => 'title', 'reason' => 'invalid_length'];
        }
        if (array_key_exists('price', $input)) {
            $price = (string) $input['price'];
            if (!is_numeric($price) || (float) $price < 0) $errors[] = ['field' => 'price', 'reason' => 'invalid_value'];
        }
        if (array_key_exists('product_type', $input)) {
            if (!in_array($input['product_type'], self::PRODUCT_TYPES, true)) {
                $errors[] = ['field' => 'product_type', 'reason' => 'invalid_value'];
            }
            // A digital product's file(s) can be an external digital_asset_url
            // (below) and/or a gated upload (ProductDigitalAssetService,
            // uploaded separately once the product exists — it needs a
            // product_id, so it can never be required at create time).
        }
        if (array_key_exists('digital_asset_url', $input) && $input['digital_asset_url'] !== null) {
            $url = (string) $input['digital_asset_url'];
            $parts = parse_url($url);
            if (strlen($url) > 2048 || filter_var($url, FILTER_VALIDATE_URL) === false
                || !in_array(strtolower((string) ($parts['scheme'] ?? '')), ['https', 'http'], true)
                || ($parts['host'] ?? '') === '' || isset($parts['user']) || isset($parts['pass'])) {
                $errors[] = ['field' => 'digital_asset_url', 'reason' => 'invalid_format'];
            }
        }
        if (array_key_exists('digital_asset_metadata', $input) && $input['digital_asset_metadata'] !== null) {
            if (!is_array($input['digital_asset_metadata'])) {
                $errors[] = ['field' => 'digital_asset_metadata', 'reason' => 'invalid_value'];
            }
        }
        if (array_key_exists('visibility', $input) && !in_array($input['visibility'], self::VISIBILITIES, true)) $errors[] = ['field' => 'visibility', 'reason' => 'invalid_value'];
        if ($input === [] && !$partial) $errors[] = ['field' => '_', 'reason' => 'empty_update'];
        return $errors;
    }
}