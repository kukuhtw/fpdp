<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Http\JsonEnvelope;
use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Services\Auth\AuthService;
use App\Services\Marketplace\MarketplaceService;

final class MarketplaceController
{
    public function __construct(
        private readonly AuthService $auth,
        private readonly MarketplaceService $marketplace,
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
        return JsonEnvelope::success($this->marketplace->createOrder((int) $context['node']['id'], $request->json() ?? []), 201);
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
}