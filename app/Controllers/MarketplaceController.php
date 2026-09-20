<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Http\JsonEnvelope;
use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Services\Analytics\AnalyticsService;
use App\Services\Auth\AuthService;
use App\Services\Marketplace\MarketplaceService;

final class MarketplaceController
{
    public function __construct(
        private readonly AuthService $auth,
        private readonly MarketplaceService $marketplace,
        private readonly ?AnalyticsService $analytics = null,
    ) {
    }

    // ---- Products ----

    public function listProducts(Request $request): Response
    {
        $context = $this->auth->authenticate($request->bearerToken());
        $result = $this->marketplace->listProducts((int) $context['node']['id'], $request->query);
        return JsonEnvelope::collection($result['items'], $result['next_cursor'], $result['has_more']);
    }

    public function createProduct(Request $request): Response
    {
        $context = $this->auth->authenticate($request->bearerToken());
        return JsonEnvelope::success($this->marketplace->createProduct((int) $context['node']['id'], $request->json() ?? []), 201);
    }

    public function showProduct(Request $request, array $params): Response
    {
        return JsonEnvelope::success($this->marketplace->getProduct($params['productId']));
    }

    public function updateProduct(Request $request, array $params): Response
    {
        $context = $this->auth->authenticate($request->bearerToken());
        return JsonEnvelope::success($this->marketplace->updateProduct((int) $context['node']['id'], $params['productId'], $request->json() ?? []));
    }

    // ---- Orders ----

    public function listOrders(Request $request): Response
    {
        $context = $this->auth->authenticate($request->bearerToken());
        $result = $this->marketplace->listOrders((int) $context['node']['id'], $request->query);
        return JsonEnvelope::collection($result['items'], $result['next_cursor'], $result['has_more']);
    }

    public function createOrder(Request $request): Response
    {
        $context = $this->auth->authenticate($request->bearerToken());
        $order = $this->marketplace->createOrder((int) $context['node']['id'], $request->json() ?? []);

        // Note: order creation is currently owner-authenticated (no public
        // checkout exists yet), so this does not yet represent a genuine
        // anonymous-visitor conversion — see the Analytics section of the
        // progress report.
        $this->analytics?->recordShopConversion((int) $context['node']['id'], (string) $order['public_id']);

        return JsonEnvelope::success($order, 201);
    }

    public function showOrder(Request $request, array $params): Response
    {
        return JsonEnvelope::success($this->marketplace->getOrder($params['orderId']));
    }

    public function updateOrderStatus(Request $request, array $params): Response
    {
        $context = $this->auth->authenticate($request->bearerToken());
        $input = $request->json() ?? [];
        return JsonEnvelope::success($this->marketplace->updateOrderStatus((int) $context['node']['id'], $params['orderId'], (string) ($input['status'] ?? '')));
    }

    // ---- Digital goods ----

    /**
     * GET /api/v1/products/{productId}/download
     *
     * Returns the download URL for a purchased digital product. The caller
     * must have a COMPLETED or CONFIRMED order containing this product.
     *
     * @param array<string, string> $params
     */
    public function getDigitalDownload(Request $request, array $params): Response
    {
        $context = $this->auth->authenticate($request->bearerToken());

        $product = $this->marketplace->getProduct($params['productId']);

        if (($product['product_type'] ?? 'PHYSICAL') !== 'DIGITAL') {
            throw new \App\Core\Exceptions\ValidationException([['field' => 'product_type', 'reason' => 'not_a_digital_product']]);
        }

        // Verify the caller has a completed order for this product
        $this->marketplace->verifyDigitalPurchase((int) $context['user']['id'], (int) $product['id']);

        return JsonEnvelope::success([
            'product_id' => $product['public_id'],
            'title' => $product['title'],
            'digital_asset_url' => $product['digital_asset_url'],
            'digital_asset_metadata' => $product['digital_asset_metadata'],
        ]);
    }
}