<?php

declare(strict_types=1);

namespace App\Services\Marketplace;

use App\Core\Exceptions\ForbiddenException;
use App\Core\Exceptions\NotFoundException;
use App\Core\Exceptions\ValidationException;
use App\Core\Uuid;
use App\Repositories\OrderItemRepository;
use App\Repositories\OrderRepository;
use App\Repositories\ProductRepository;

final class MarketplaceService
{
    private const PRODUCT_FIELDS = ['title', 'description', 'price', 'currency', 'status', 'visibility', 'media'];
    private const VISIBILITIES = ['PUBLIC', 'UNLISTED', 'PRIVATE'];
    private const ORDER_STATUSES = ['PENDING', 'CONFIRMED', 'PROCESSING', 'COMPLETED', 'CANCELLED', 'REFUNDED'];

    public function __construct(
        private readonly ProductRepository $products,
        private readonly OrderRepository $orders,
        private readonly OrderItemRepository $orderItems,
    ) {
    }

    public function createProduct(int $nodeId, array $input): array
    {
        $errors = $this->validateProductInput($input, false);
        if ($errors !== []) throw new ValidationException($errors);
        return $this->products->create(Uuid::v4(), $nodeId, $input['title'], $input['description'] ?? null, (string) ($input['price'] ?? '0'), $input['currency'] ?? 'IDR', $input['media'] ?? null);
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

    public function createOrder(int $nodeId, array $input): array
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
            $unitPrice = $product['price'];
            $subtotal = (string) ((float) $unitPrice * $qty);
            $totalAmount = (string) ((float) $totalAmount + (float) $subtotal);
            $orderItems[] = ['product' => $product, 'quantity' => $qty, 'unit_price' => $unitPrice, 'subtotal' => $subtotal];
        }
        if ($errors !== []) throw new ValidationException($errors);
        $orderPubId = Uuid::v4();
        $order = $this->orders->create($orderPubId, $nodeId, $totalAmount, $input['currency'] ?? 'IDR', $input['buyer_email'] ?? null, $input['buyer_name'] ?? null, $input['notes'] ?? null);
        foreach ($orderItems as $oi) {
            $this->orderItems->create((int) $order['id'], (int) $oi['product']['id'], ['public_id' => $oi['product']['public_id'], 'title' => $oi['product']['title'], 'price' => $oi['product']['price']], $oi['quantity'], $oi['unit_price'], $oi['subtotal']);
        }
        return $this->orders->findByPublicId($orderPubId);
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
        if (array_key_exists('visibility', $input) && !in_array($input['visibility'], self::VISIBILITIES, true)) $errors[] = ['field' => 'visibility', 'reason' => 'invalid_value'];
        if ($input === [] && !$partial) $errors[] = ['field' => '_', 'reason' => 'empty_update'];
        return $errors;
    }
}