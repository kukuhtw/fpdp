<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Config;
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
    /** How long a signed digital-download link from getDigitalDownload() works. */
    private const DOWNLOAD_LINK_TTL = 900;

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

    /**
     * Public product view; the owning node's owner additionally sees private
     * and draft products and the external download URL.
     */
    public function showProduct(Request $request, array $params): Response
    {
        $ownerNodeId = $this->optionalOwnerNodeId($request);
        if ($ownerNodeId !== null) {
            $product = $this->marketplace->getProduct($params['productId']);
            if ((int) $product['node_id'] === $ownerNodeId) {
                return JsonEnvelope::success($product);
            }
        }

        return JsonEnvelope::success($this->marketplace->getPublicProduct($params['productId']));
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
        $nodeId = (int) $context['node']['id'];
        $before = $this->marketplace->getProduct($params['productId']);
        $product = $this->marketplace->updateProduct($nodeId, $params['productId'], $request->json() ?? []);

        // Followers see a product only while it's promoted, active, and not
        // private: entering that state is a Create, staying in it an Update,
        // leaving it a Delete that withdraws it from their timelines.
        $wasFederated = FederationService::isFederatableProduct($before);
        if (FederationService::isFederatableProduct($product)) {
            $this->federateProduct($nodeId, $product, $wasFederated ? 'Update' : 'Create');
        } elseif ($wasFederated) {
            $this->federateProduct($nodeId, $before, 'Delete');
        }

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

    /**
     * The selling node's owner, or the visitor who placed the order (the
     * payment thank-you page) — nobody else.
     */
    public function showOrder(Request $request, array $params): Response
    {
        $ownerNodeId = $this->optionalOwnerNodeId($request);
        if ($ownerNodeId !== null) {
            return JsonEnvelope::success($this->marketplace->getOrder($params['orderId'], $ownerNodeId));
        }

        return JsonEnvelope::success($this->marketplace->getOrder($params['orderId'], null, (int) $this->requireVisitor($request)['id']));
    }

    /**
     * The owner's node id when the bearer token is an owner token, null for
     * a visitor token or no token (those endpoints then fall back to their
     * public/visitor behaviour).
     */
    private function optionalOwnerNodeId(Request $request): ?int
    {
        if ($request->bearerToken() === null || $request->bearerToken() === '') {
            return null;
        }
        try {
            return (int) $this->auth->authenticate($request->bearerToken())['node']['id'];
        } catch (\App\Core\Exceptions\UnauthorizedException) {
            return null;
        }
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

        $origin = $request->scheme() . '://' . $profile['node_domain'];
        $handle = rawurlencode((string) $params['handle']);
        $result = $this->marketplace->checkout(
            (int) $profile['node_id'],
            $visitor,
            $request->json() ?? [],
            "{$origin}/payment/thank-you?type=order&handle={$handle}",
            "{$origin}/@{$handle}",
        );

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

        // Each file also gets a plain link valid for DOWNLOAD_LINK_TTL
        // seconds, so the browser downloads it natively. Fetching the file
        // with the bearer token and saving a blob from JavaScript silently
        // does nothing on many phones and in-app browsers (WhatsApp,
        // Instagram, Facebook).
        $expires = (string) (time() + self::DOWNLOAD_LINK_TTL);
        $assets = array_map(function (array $asset) use ($product, $visitor, $expires): array {
            $kind = strtoupper((string) $asset['kind']);

            return $asset + ['download_url' => sprintf(
                '/api/v1/products/%s/digital-assets/%s/file?visitor=%d&expires=%s&token=%s',
                rawurlencode((string) $product['public_id']),
                rawurlencode(strtolower($kind)),
                (int) $visitor['id'],
                $expires,
                self::downloadToken((string) $product['public_id'], $kind, (int) $visitor['id'], $expires),
            ), 'download_expires_at' => gmdate('c', (int) $expires)];
        }, $this->productAssets?->listForProduct((int) $product['id']) ?? []);

        return JsonEnvelope::success([
            'product_id' => $product['public_id'],
            'title' => $product['title'],
            'digital_asset_url' => $product['digital_asset_url'],
            'digital_asset_metadata' => $product['digital_asset_metadata'],
            'digital_assets' => $assets,
        ]);
    }

    /**
     * GET /api/v1/products/{productId}/digital-assets/{kind}/file?visitor=&expires=&token=
     *
     * The same gated file as downloadDigitalAsset(), reached through the
     * short-lived signed link from getDigitalDownload() instead of a bearer
     * header, so a plain <a href> works in every browser. The purchase is
     * checked again at download time: a refund stops the link at once.
     *
     * @param array<string, string> $params
     */
    public function downloadDigitalAssetByLink(Request $request, array $params): Response
    {
        $visitorId = (string) ($request->query['visitor'] ?? '');
        $expires = (string) ($request->query['expires'] ?? '');
        $token = (string) ($request->query['token'] ?? '');
        $kind = strtoupper((string) ($params['kind'] ?? ''));
        $valid = ctype_digit($visitorId) && ctype_digit($expires) && (int) $expires >= time()
            && hash_equals(self::downloadToken((string) $params['productId'], $kind, (int) $visitorId, $expires), $token);
        if (!$valid) {
            throw new \App\Core\Exceptions\ForbiddenException('This download link has expired. Open the product page again to get a new one.');
        }

        $product = $this->marketplace->getProduct($params['productId']);
        if (($product['product_type'] ?? 'PHYSICAL') !== 'DIGITAL') {
            throw new \App\Core\Exceptions\ValidationException([['field' => 'product_type', 'reason' => 'not_a_digital_product']]);
        }
        $this->marketplace->verifyDigitalPurchase((int) $visitorId, (int) $product['id']);

        $file = $this->requireProductAssets()->readForDownload((int) $product['id'], $kind);

        return Response::binary($file['content'], $file['content_type'], $file['filename']);
    }

    private static function downloadToken(string $productPublicId, string $kind, int $visitorId, string $expires): string
    {
        return hash_hmac('sha256', "fpdp-asset-download|{$productPublicId}|{$kind}|{$visitorId}|{$expires}", (string) Config::get('APP_KEY', ''));
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