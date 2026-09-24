<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Http\JsonEnvelope;
use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Services\Analytics\AnalyticsService;
use App\Services\Auth\AuthService;
use App\Services\Federation\FederationService;
use App\Services\Marketplace\MarketplaceService;
use App\Services\Marketplace\ProductDigitalAssetService;
use App\Services\Profile\ProfileService;
use App\Services\Visitor\VisitorAuthService;

final class MarketplaceController
{
    public function __construct(
        private readonly AuthService $auth,
        private readonly MarketplaceService $marketplace,
        private readonly ?AnalyticsService $analytics = null,
        private readonly ?ProfileService $profiles = null,
        private readonly ?VisitorAuthService $visitorAuth = null,
        private readonly ?ProductDigitalAssetService $productAssets = null,
        private readonly ?FederationService $federation = null,
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
        $product = $this->marketplace->createProduct((int) $context['node']['id'], $request->json() ?? []);
        $this->federateProduct((int) $context['node']['id'], $product, 'Create');
        return JsonEnvelope::success($product, 201);
    }

    public function showProduct(Request $request, array $params): Response
    {
        return JsonEnvelope::success($this->marketplace->getProduct($params['productId']));
    }

    /**
     * GET /api/v1/profiles/{handle}/products
     *
     * Public storefront listing: only ACTIVE+PUBLIC products, regardless of
     * what the caller asks for — `mine` is stripped so this can never be
     * used to enumerate a node's private/draft products without auth (that
     * branch of MarketplaceService::listProducts() is for the owner-authed
     * /api/v1/products route only).
     *
     * @param array<string, string> $params
     */
    public function listPublicProducts(Request $request, array $params): Response
    {
        $profile = $this->requireProfiles()->getPublicProfile($params['handle']);
        $query = $request->query;
        unset($query['mine']);

        $result = $this->marketplace->listProducts((int) $profile['node_id'], $query);
        return JsonEnvelope::collection($result['items'], $result['next_cursor'], $result['has_more']);
    }

    public function updateProduct(Request $request, array $params): Response
    {
        $context = $this->auth->authenticate($request->bearerToken());
        $product = $this->marketplace->updateProduct((int) $context['node']['id'], $params['productId'], $request->json() ?? []);
        $this->federateProduct((int) $context['node']['id'], $product, 'Update');
        return JsonEnvelope::success($product);
    }

    /**
     * Federation is best-effort: a delivery-layer failure here must never
     * block the product itself from saving successfully.
     *
     * @param array<string, mixed> $product
     */
    private function federateProduct(int $nodeId, array $product, string $activityType): void
    {
        if ($this->federation === null) {
            return;
        }
        try {
            $this->federation->publishLocalProduct($nodeId, $product, $activityType);
        } catch (\Throwable) {
            // Best-effort — the product itself already saved successfully.
        }
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

    /**
     * POST /api/v1/profiles/{handle}/orders
     *
     * Visitor checkout: requires Google sign-in, charges the node's active
     * payment gateway. This is the genuine buyer-facing flow — createOrder()
     * above remains for the owner manually recording an offline sale.
     *
     * @param array<string, string> $params
     */
    public function checkout(Request $request, array $params): Response
    {
        $profile = $this->requireProfiles()->getPublicProfile($params['handle']);
        $visitor = $this->requireVisitor($request);

        $result = $this->marketplace->checkout((int) $profile['node_id'], $visitor, $request->json() ?? []);

        $this->analytics?->recordShopConversion((int) $profile['node_id'], (string) $result['order']['public_id']);

        return JsonEnvelope::success($result, 201);
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
        $visitor = $this->requireVisitor($request);

        $product = $this->marketplace->getProduct($params['productId']);

        if (($product['product_type'] ?? 'PHYSICAL') !== 'DIGITAL') {
            throw new \App\Core\Exceptions\ValidationException([['field' => 'product_type', 'reason' => 'not_a_digital_product']]);
        }

        // Verify the caller (the buyer, not the store owner) has a completed order for this product
        $this->marketplace->verifyDigitalPurchase((int) $visitor['id'], (int) $product['id']);

        return JsonEnvelope::success([
            'product_id' => $product['public_id'],
            'title' => $product['title'],
            'digital_asset_url' => $product['digital_asset_url'],
            'digital_asset_metadata' => $product['digital_asset_metadata'],
            'digital_assets' => $this->productAssets?->listForProduct((int) $product['id']) ?? [],
        ]);
    }

    /**
     * POST /api/v1/me/products/{productId}/digital-assets
     *
     * Owner-only. Uploads (or replaces) a gated file for a DIGITAL product —
     * a PDF and/or a source-code archive, one of each at most. Never served
     * publicly; downloadDigitalAsset() below gates every read on proof of
     * purchase, same as the legacy digital_asset_url flow.
     *
     * @param array<string, string> $params
     */
    public function uploadDigitalAsset(Request $request, array $params): Response
    {
        $context = $this->auth->authenticate($request->bearerToken());
        $result = $this->requireProductAssets()->upload(
            (int) $context['node']['id'],
            $params['productId'],
            $request->json() ?? [],
        );

        // A newly attached/replaced file changes what the product offers,
        // so a promoted product re-announces to followers the same way an
        // edit to its title/price/description would.
        $this->federateProduct((int) $context['node']['id'], $this->marketplace->getProduct($params['productId']), 'Update');

        return JsonEnvelope::success($result, 201);
    }

    /**
     * GET /api/v1/me/products/{productId}/digital-assets
     *
     * Owner-only metadata list (kind, filename, size — no file content) of
     * what's already uploaded for a product, for the edit form.
     *
     * @param array<string, string> $params
     */
    public function listDigitalAssets(Request $request, array $params): Response
    {
        $context = $this->auth->authenticate($request->bearerToken());
        $result = $this->requireProductAssets()->listForOwnedProduct((int) $context['node']['id'], $params['productId']);

        return JsonEnvelope::success($result);
    }

    /**
     * GET /api/v1/products/{productId}/digital-assets/{kind}/download
     *
     * Visitor-facing, gated: streams the uploaded PDF or source-code file
     * for a DIGITAL product once the caller's purchase is verified — same
     * gating call as getDigitalDownload() above, just serving the file
     * itself instead of a legacy external URL.
     *
     * @param array<string, string> $params
     */
    public function downloadDigitalAsset(Request $request, array $params): Response
    {
        $visitor = $this->requireVisitor($request);
        $product = $this->marketplace->getProduct($params['productId']);

        if (($product['product_type'] ?? 'PHYSICAL') !== 'DIGITAL') {
            throw new \App\Core\Exceptions\ValidationException([['field' => 'product_type', 'reason' => 'not_a_digital_product']]);
        }

        $this->marketplace->verifyDigitalPurchase((int) $visitor['id'], (int) $product['id']);

        $file = $this->requireProductAssets()->readForDownload((int) $product['id'], (string) ($params['kind'] ?? ''));

        return Response::binary($file['content'], $file['content_type'], $file['filename']);
    }

    private function requireProductAssets(): ProductDigitalAssetService
    {
        if ($this->productAssets === null) {
            throw new \RuntimeException('MarketplaceController was not wired with a ProductDigitalAssetService.');
        }

        return $this->productAssets;
    }

    /**
     * @return array<string, mixed>
     */
    private function requireVisitor(Request $request): array
    {
        if ($this->visitorAuth === null) {
            throw new \App\Core\Exceptions\UnauthorizedException();
        }

        return $this->visitorAuth->authenticate($request->bearerToken())['visitor'];
    }

    private function requireProfiles(): ProfileService
    {
        if ($this->profiles === null) {
            throw new \RuntimeException('MarketplaceController was not wired with a ProfileService.');
        }

        return $this->profiles;
    }
}