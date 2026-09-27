<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Exceptions\NotFoundException;
use App\Core\Http\JsonEnvelope;
use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Repositories\NodeRepository;
use App\Services\Auth\AuthService;
use App\Services\Marketplace\FederatedOrderService;
use App\Services\Marketplace\FederatedPurchaseService;
use App\Services\Security\RateLimiter;

/**
 * Node-to-node orders (FEDERATION-CONCEPT §11b).
 *
 * Seller side, called by other FPDP nodes with HTTP Signatures:
 *   POST /api/v1/federation/orders
 *   GET  /api/v1/federation/orders/{orderId}
 *   GET  /api/v1/federation/orders/{orderId}/downloads/{productId}/{kind}?expires=&token=
 *
 * Buyer side, for this node's owner:
 *   GET  /api/v1/me/fediverse-shop
 *   GET  /api/v1/me/purchases
 *   POST /api/v1/me/purchases
 *   POST /api/v1/me/purchases/{purchaseId}/refresh
 */
final class FederatedCommerceController
{
    public function __construct(
        private readonly RateLimiter $rateLimiter,
        private readonly NodeRepository $nodes,
        private readonly ?FederatedOrderService $orders = null,
        private readonly ?AuthService $auth = null,
        private readonly ?FederatedPurchaseService $purchases = null,
    ) {
    }

    public function receiveOrder(Request $request): Response
    {
        $body = (string) $request->body;
        $claimed = json_decode($body, true);
        $senderHost = strtolower((string) parse_url(is_array($claimed) ? (string) ($claimed['actor'] ?? '') : '', PHP_URL_HOST));
        $this->rateLimiter->hit('federated_order', $senderHost !== '' ? $senderHost : $request->ipAddress, 30, 3600);

        $result = $this->requireOrders()->receive($this->localNode(), $request->headers, $body, $request->path);

        return JsonEnvelope::success(['order' => $result['order']], $result['duplicate'] ? 200 : 201);
    }

    /**
     * @param array<string, string> $params
     */
    public function orderStatus(Request $request, array $params): Response
    {
        $this->rateLimiter->hit('federated_order_status', $request->ipAddress, 240, 600);

        return JsonEnvelope::success($this->requireOrders()->status($this->localNode(), (string) $params['orderId'], $request->headers, $request->path));
    }

    /**
     * @param array<string, string> $params
     */
    public function download(Request $request, array $params): Response
    {
        $this->rateLimiter->hit('federated_order_download', $request->ipAddress, 60, 600);
        $file = $this->requireOrders()->download(
            (string) $params['orderId'],
            (string) $params['productId'],
            (string) $params['kind'],
            (string) ($request->query['expires'] ?? ''),
            (string) ($request->query['token'] ?? ''),
        );

        return Response::binary($file['content'], $file['content_type'], $file['filename']);
    }

    public function shop(Request $request): Response
    {
        $context = $this->owner($request);

        return JsonEnvelope::success(['items' => $this->requirePurchases()->shop($context)]);
    }

    public function listPurchases(Request $request): Response
    {
        $context = $this->owner($request);

        return JsonEnvelope::success(['items' => $this->requirePurchases()->list($context)]);
    }

    public function placePurchase(Request $request): Response
    {
        $context = $this->owner($request);
        $this->rateLimiter->hit('federated_purchase', (string) $context['user']['id'], 20, 3600);

        return JsonEnvelope::success($this->requirePurchases()->place($context, $request->json() ?? []), 201);
    }

    /**
     * @param array<string, string> $params
     */
    public function refreshPurchase(Request $request, array $params): Response
    {
        $context = $this->owner($request);
        $this->rateLimiter->hit('federated_purchase_refresh', (string) $context['user']['id'], 120, 600);

        return JsonEnvelope::success($this->requirePurchases()->refresh($context, (string) $params['purchaseId']));
    }

    /**
     * @return array<string, mixed>
     */
    private function owner(Request $request): array
    {
        if ($this->auth === null) {
            throw new \RuntimeException('FederatedCommerceController was not wired with an AuthService.');
        }

        return $this->auth->authenticate($request->bearerToken());
    }

    /**
     * @return array<string, mixed>
     */
    private function localNode(): array
    {
        $node = $this->nodes->findFirst();
        if ($node === null) {
            throw new NotFoundException('This node is not set up yet.');
        }

        return $node;
    }

    private function requireOrders(): FederatedOrderService
    {
        return $this->orders ?? throw new \RuntimeException('FederatedCommerceController was not wired with a FederatedOrderService.');
    }

    private function requirePurchases(): FederatedPurchaseService
    {
        return $this->purchases ?? throw new \RuntimeException('FederatedCommerceController was not wired with a FederatedPurchaseService.');
    }
}
