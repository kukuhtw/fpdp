<?php

declare(strict_types=1);

use App\Controllers\AnalyticsController;
use App\Controllers\AuthController;
use App\Controllers\CvController;
use App\Controllers\ContentPageController;
use App\Controllers\HealthController;
use App\Controllers\HomeController;
use App\Controllers\MediaController;
use App\Controllers\ProfileController;
use App\Controllers\FederationController;
use App\Controllers\ExternalContentController;
use App\Controllers\FacebookIntegrationController;
use App\Controllers\LinkedInIntegrationController;
use App\Controllers\DashboardController;
use App\Controllers\MarketplaceController;
use App\Controllers\PaymentController;
use App\Controllers\PostController;
use App\Controllers\VisitorAuthController;
use App\Core\Config;
use App\Core\Database;
use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Core\Router;
use App\Repositories\AnalyticsEventRepository;
use App\Repositories\AuditEventRepository;
use App\Repositories\AuthTokenRepository;
use App\Repositories\CvAccessGrantRepository;
use App\Repositories\CvDocumentRepository;
use App\Repositories\NodeRepository;
use App\Repositories\ProfileRepository;
use App\Repositories\PostRepository;
use App\Repositories\RemoteActorRepository;
use App\Repositories\RemoteNodeRepository;
use App\Repositories\FederatedConnectionRepository;
use App\Repositories\FederatedPostRepository;
use App\Repositories\ExternalFeedSourceRepository;
use App\Repositories\ExternalAccountRepository;
use App\Repositories\ExternalPostRepository;
use App\Repositories\OrderItemRepository;
use App\Repositories\OrderRepository;
use App\Repositories\PaymentGatewayConfigRepository;
use App\Repositories\PaymentRepository;
use App\Repositories\ProductRepository;
use App\Repositories\RateLimitRepository;
use App\Repositories\UserRepository;
use App\Repositories\VisitorRepository;
use App\Repositories\VisitorTokenRepository;
use App\Services\Auth\AuthService;
use App\Services\Analytics\AnalyticsService;
use App\Services\Dashboard\DashboardService;
use App\Services\Federation\FederationService;
use App\Services\Cv\CvAccessService;
use App\Services\Cv\CvDocumentService;
use App\Services\Payment\PaymentService;
use App\Services\Profile\ProfileService;
use App\Services\Content\MediaUploadService;
use App\Services\Content\PostService;
use App\Services\External\SyncWorker;
use App\Services\External\FacebookIntegrationService;
use App\Services\External\LinkedInIntegrationService;
use App\Services\Marketplace\MarketplaceService;
use App\Services\Security\RateLimiter;
use App\Services\Visitor\GoogleOAuthClient;
use App\Services\Visitor\OAuthStateSigner;
use App\Services\Visitor\VisitorAuthService;

$router = new Router();

// Database connections are created lazily inside each closure so that
// routes with no persistence needs (/api/v1/health) never touch the DB.
$buildAuthService = static function (): AuthService {
    $connection = Database::connection();

    return new AuthService(
        new NodeRepository($connection),
        new UserRepository($connection),
        new ProfileRepository($connection),
        new AuthTokenRepository($connection),
    );
};

$buildProfileService = static function (): ProfileService {
    return new ProfileService(new ProfileRepository(Database::connection()));
};

$buildAnalyticsService = static function (): AnalyticsService {
    return new AnalyticsService(new AnalyticsEventRepository(Database::connection()));
};

$buildRateLimiter = static function (): RateLimiter {
    return new RateLimiter(new RateLimitRepository(Database::connection()));
};

$buildPostController = static function () use ($buildAuthService, $buildAnalyticsService): PostController {
    return new PostController(
        $buildAuthService(),
        new PostService(new PostRepository(Database::connection())),
        $buildAnalyticsService(),
    );
};

$buildContentPageController = static function (): ContentPageController {
    $connection = Database::connection();

    return new ContentPageController(
        new PostService(new PostRepository($connection)),
        new ProfileService(new ProfileRepository($connection)),
    );
};

$buildVisitorAuthService = static function (): VisitorAuthService {
    $connection = Database::connection();

    $googleClient = new GoogleOAuthClient(
        Config::get('GOOGLE_CLIENT_ID', ''),
        Config::get('GOOGLE_CLIENT_SECRET', ''),
    );

    return new VisitorAuthService(
        $googleClient,
        new VisitorRepository($connection),
        new VisitorTokenRepository($connection),
    );
};

$buildVisitorAuthController = static function () use ($buildVisitorAuthService): VisitorAuthController {
    $connection = Database::connection();
    $stateSigner = new OAuthStateSigner(Config::get('APP_KEY', ''));

    return new VisitorAuthController(
        new ProfileService(new ProfileRepository($connection)),
        $buildVisitorAuthService(),
        $stateSigner,
    );
};

$cvStorageDirectory = dirname(__DIR__) . '/storage/cv';
$mediaStorageDirectory = dirname(__DIR__) . '/storage/media';

$buildMediaController = static function () use ($buildAuthService, $mediaStorageDirectory): MediaController {
    return new MediaController($buildAuthService(), new MediaUploadService($mediaStorageDirectory));
};

$buildPaymentService = static function (): PaymentService {
    $connection = Database::connection();

    return new PaymentService(
        payments: new PaymentRepository($connection),
        gatewayConfigs: new PaymentGatewayConfigRepository($connection),
    );
};

$buildCvAccessService = static function () use ($cvStorageDirectory, $buildPaymentService): CvAccessService {
    $connection = Database::connection();

    return new CvAccessService(
        new CvDocumentRepository($connection),
        new CvAccessGrantRepository($connection),
        $buildPaymentService(),
        $cvStorageDirectory,
    );
};

$buildCvController = static function () use ($buildAuthService, $buildVisitorAuthService, $buildCvAccessService, $cvStorageDirectory): CvController {
    $connection = Database::connection();

    return new CvController(
        $buildAuthService(),
        new ProfileService(new ProfileRepository($connection)),
        $buildVisitorAuthService(),
        new CvDocumentService(new CvDocumentRepository($connection), new CvAccessGrantRepository($connection), $cvStorageDirectory),
        $buildCvAccessService(),
    );
};

$buildPaymentController = static function () use ($buildAuthService, $buildCvAccessService, $buildPaymentService): PaymentController {
    return new PaymentController(
        $buildAuthService(),
        $buildPaymentService(),
        $buildCvAccessService(),
    );
};
$buildFederationService = static function (): FederationService {
    $connection = Database::connection();

    return new FederationService(
        new FederatedConnectionRepository($connection),
        new RemoteActorRepository($connection),
        new RemoteNodeRepository($connection),
        new FederatedPostRepository($connection),
        new ProfileRepository($connection),
    );
};

$buildFederationController = static function () use ($buildAuthService, $buildFederationService): FederationController {
    return new FederationController($buildAuthService(), $buildFederationService());
};

$buildDashboardController = static function () use ($buildAuthService, $buildFederationService, $buildAnalyticsService): DashboardController {
    $connection = Database::connection();

    return new DashboardController(
        $buildAuthService(),
        new DashboardService(
            new NodeRepository($connection),
            new PostRepository($connection),
            new ExternalPostRepository($connection),
            new FederatedPostRepository($connection),
            new ProductRepository($connection),
            new OrderRepository($connection),
            new PaymentRepository($connection),
            $buildFederationService(),
            new AuditEventRepository($connection),
            $buildAnalyticsService(),
        ),
    );
};

$buildAnalyticsController = static function () use ($buildAuthService, $buildProfileService, $buildAnalyticsService): AnalyticsController {
    return new AnalyticsController($buildAuthService(), $buildProfileService(), $buildAnalyticsService());
};
$buildMarketplaceController = static function () use ($buildAuthService, $buildAnalyticsService): MarketplaceController {
    $connection = Database::connection();
    return new MarketplaceController(
        $buildAuthService(),
        new MarketplaceService(
            new ProductRepository($connection),
            new OrderRepository($connection),
            new OrderItemRepository($connection),
        ),
        $buildAnalyticsService(),
    );
};
$buildExternalContentController = static function () use ($buildAuthService): ExternalContentController {
    $connection = Database::connection();
    return new ExternalContentController(
        $buildAuthService(),
        new SyncWorker(
            new ExternalFeedSourceRepository($connection),
            new ExternalPostRepository($connection),
        ),
        new ExternalFeedSourceRepository($connection),
        new ExternalPostRepository($connection),
    );
};
$buildFacebookIntegrationController = static function () use ($buildAuthService): FacebookIntegrationController {
    $connection = Database::connection();
    $accounts = new ExternalAccountRepository($connection);
    return new FacebookIntegrationController(
        $buildAuthService(),
        new FacebookIntegrationService($accounts, new ExternalFeedSourceRepository($connection)),
        $accounts,
        new OAuthStateSigner((string) Config::get('APP_KEY', '')),
    );
};
$buildLinkedInIntegrationController = static function () use ($buildAuthService): LinkedInIntegrationController {
    $connection=Database::connection();$accounts=new ExternalAccountRepository($connection);
    return new LinkedInIntegrationController($buildAuthService(),new LinkedInIntegrationService($accounts,new ExternalFeedSourceRepository($connection)),$accounts,new OAuthStateSigner((string)Config::get('APP_KEY','')));
};

$router->get('/', function (Request $request, array $params) use ($buildContentPageController): Response {
    $connection = Database::connection();

    $home = new HomeController(
        new NodeRepository($connection),
        new ProfileRepository($connection),
        $buildContentPageController(),
    );

    return Response::html($home->index());
});

$router->get('/timeline', function (Request $request, array $params) use ($buildContentPageController): Response {
    return Response::html($buildContentPageController()->timeline($request->query));
});

$router->get('/@{handle}', function (Request $request, array $params) use ($buildContentPageController): Response {
    return Response::html($buildContentPageController()->profile($params['handle'], $request->query));
});

$router->get('/posts/{postId}', function (Request $request, array $params) use ($buildContentPageController): Response {
    return Response::html($buildContentPageController()->post($params['postId']));
});

$router->get('/about', function (Request $request, array $params) use ($buildContentPageController): Response {
    return Response::html($buildContentPageController()->about());
});
$router->get('/dashboard/posts', function (Request $request, array $params) use ($buildContentPageController): Response {
    return Response::html($buildContentPageController()->editor());
});

$router->get('/dashboard/posts/list', function (Request $request, array $params) use ($buildContentPageController): Response {
    return Response::html($buildContentPageController()->postsList());
});

$router->get('/dashboard/integrations', function (Request $request, array $params) use ($buildContentPageController): Response {
    return Response::html($buildContentPageController()->integrations());
});

$router->get('/dashboard/settings', function (Request $request, array $params) use ($buildContentPageController): Response {
    return Response::html($buildContentPageController()->settings());
});

$router->get('/dashboard/cv', function (Request $request, array $params) use ($buildContentPageController): Response {
    return Response::html($buildContentPageController()->cvManager());
});

$router->get('/dashboard', function (Request $request, array $params) use ($buildContentPageController): Response {
    return Response::html($buildContentPageController()->dashboardOverview());
});

$router->get('/@{handle}/cv', function (Request $request, array $params) use ($buildContentPageController): Response {
    return Response::html($buildContentPageController()->publicCv($params['handle']));
});

$router->get('/api/v1/health', function (Request $request, array $params): Response {
    return (new HealthController())->show();
});

$router->post('/api/v1/auth/register', function (Request $request, array $params) use ($buildAuthService, $buildRateLimiter): Response {
    return (new AuthController($buildAuthService(), $buildRateLimiter()))->register($request);
});

$router->post('/api/v1/auth/login', function (Request $request, array $params) use ($buildAuthService, $buildRateLimiter): Response {
    return (new AuthController($buildAuthService(), $buildRateLimiter()))->login($request);
});

$router->post('/api/v1/auth/logout', function (Request $request, array $params) use ($buildAuthService, $buildRateLimiter): Response {
    return (new AuthController($buildAuthService(), $buildRateLimiter()))->logout($request);
});

$router->get('/api/v1/me', function (Request $request, array $params) use ($buildAuthService, $buildRateLimiter): Response {
    return (new AuthController($buildAuthService(), $buildRateLimiter()))->me($request);
});

$router->get('/api/v1/profiles/{handle}', function (Request $request, array $params) use ($buildAuthService, $buildProfileService, $buildAnalyticsService): Response {
    return (new ProfileController($buildAuthService(), $buildProfileService(), $buildAnalyticsService()))->show($request, $params);
});

$router->patch('/api/v1/me/profile', function (Request $request, array $params) use ($buildAuthService, $buildProfileService): Response {
    return (new ProfileController($buildAuthService(), $buildProfileService()))->update($request);
});

$router->get('/api/v1/posts', function (Request $request, array $params) use ($buildPostController): Response {
    return $buildPostController()->index($request);
});

$router->get('/api/v1/me/posts', function (Request $request, array $params) use ($buildPostController): Response {
    return $buildPostController()->myPosts($request);
});

$router->post('/api/v1/posts', function (Request $request, array $params) use ($buildPostController): Response {
    return $buildPostController()->create($request);
});

$router->get('/api/v1/posts/{postId}', function (Request $request, array $params) use ($buildPostController): Response {
    return $buildPostController()->show($request, $params);
});

$router->patch('/api/v1/posts/{postId}', function (Request $request, array $params) use ($buildPostController): Response {
    return $buildPostController()->update($request, $params);
});

$router->delete('/api/v1/posts/{postId}', function (Request $request, array $params) use ($buildPostController): Response {
    return $buildPostController()->delete($request, $params);
});

$router->get('/api/v1/timeline', function (Request $request, array $params) use ($buildPostController): Response {
    return $buildPostController()->index($request);
});

$router->get('/api/v1/profiles/{handle}/visitor-auth/google/redirect', function (Request $request, array $params) use ($buildVisitorAuthController): Response {
    return $buildVisitorAuthController()->redirect($request, $params);
});

$router->get('/api/v1/profiles/{handle}/visitor-auth/google/callback', function (Request $request, array $params) use ($buildVisitorAuthController): Response {
    return $buildVisitorAuthController()->callback($request, $params);
});

$router->post('/api/v1/me/cv', function (Request $request, array $params) use ($buildCvController): Response {
    return $buildCvController()->upload($request);
});

$router->get('/api/v1/profiles/{handle}/cv', function (Request $request, array $params) use ($buildCvController): Response {
    return $buildCvController()->show($request, $params);
});

$router->post('/api/v1/profiles/{handle}/cv/access', function (Request $request, array $params) use ($buildCvController): Response {
    return $buildCvController()->grantAccess($request, $params);
});

$router->get('/api/v1/profiles/{handle}/cv/download', function (Request $request, array $params) use ($buildCvController): Response {
    return $buildCvController()->download($request, $params);
});

$router->post('/api/v1/me/media', function (Request $request, array $params) use ($buildMediaController): Response {
    return $buildMediaController()->upload($request);
});

$router->get('/api/v1/media/{key}', function (Request $request, array $params) use ($buildMediaController): Response {
    return $buildMediaController()->show($params);
});

$router->post('/api/v1/payments/webhook/{gateway}', function (Request $request, array $params) use ($buildPaymentController): Response {
    return $buildPaymentController()->webhook($request, $params);
});

$router->get('/api/v1/me/dashboard/overview', function (Request $request, array $params) use ($buildDashboardController): Response {
    return $buildDashboardController()->overview($request);
});

$router->get('/api/v1/me/dashboard/payments', function (Request $request, array $params) use ($buildPaymentController): Response {
    return $buildPaymentController()->summary($request);
});

$router->get('/api/v1/me/payment-gateways', function (Request $request, array $params) use ($buildPaymentController): Response {
    return $buildPaymentController()->listGateways($request);
});

$router->patch('/api/v1/me/payment-gateways/{code}', function (Request $request, array $params) use ($buildPaymentController): Response {
    return $buildPaymentController()->updateGateway($request, $params);
});

$router->put('/api/v1/me/payment-gateways/{code}/activate', function (Request $request, array $params) use ($buildPaymentController): Response {
    return $buildPaymentController()->activateGateway($request, $params);
});

$router->get('/api/v1/me/dashboard/analytics', function (Request $request, array $params) use ($buildAnalyticsController): Response {
    return $buildAnalyticsController()->summary($request);
});

$router->post('/api/v1/profiles/{handle}/track/outbound-click', function (Request $request, array $params) use ($buildAnalyticsController): Response {
    return $buildAnalyticsController()->trackOutboundClick($request, $params);
});

$router->get('/api/v1/profiles/{handle}/federated-connections', function (Request $request, array $params) use ($buildFederationController): Response {
    return $buildFederationController()->listPublicByHandle($request, $params);
});

$router->get('/api/v1/me/federated-connections', function (Request $request, array $params) use ($buildFederationController): Response {
    return $buildFederationController()->listOwnConnections($request);
});

$router->patch('/api/v1/me/federated-connections/{connectionId}', function (Request $request, array $params) use ($buildFederationController): Response {
    return $buildFederationController()->updateConnection($request, $params);
});

$router->get('/api/v1/me/federation/summary', function (Request $request, array $params) use ($buildFederationController): Response {
    return $buildFederationController()->summary($request);
});

$router->patch('/api/v1/me/federation/capabilities', function (Request $request, array $params) use ($buildFederationController): Response {
    return $buildFederationController()->updateCapabilities($request);
});

$router->get('/api/v1/me/federation/remote-nodes', function (Request $request, array $params) use ($buildFederationController): Response {
    return $buildFederationController()->listRemoteNodes($request);
});

$router->patch('/api/v1/me/federation/remote-nodes/{domain}/trust', function (Request $request, array $params) use ($buildFederationController): Response {
    return $buildFederationController()->updateRemoteNodeTrust($request, $params);
});

$router->post('/api/v1/me/federation/discover', function (Request $request, array $params) use ($buildFederationController): Response {
    return $buildFederationController()->discoverRemoteNode($request, $params);
});

$router->get('/api/v1/federation/capability', function (Request $request, array $params) use ($buildFederationController): Response {
    return $buildFederationController()->capability($request);
});

$router->post('/api/v1/federation/ensure-key', function (Request $request, array $params) use ($buildFederationController): Response {
    return $buildFederationController()->ensureKey($request);
});

$router->post('/api/v1/federation/inbox', function (Request $request, array $params) use ($buildFederationController): Response {
    return $buildFederationController()->inbox($request);
});

$router->post('/api/v1/federation/outbox', function (Request $request, array $params) use ($buildFederationController): Response {
    return $buildFederationController()->outbox($request);
});
$router->get('/api/v1/external/posts', function (Request $request, array $params) use ($buildExternalContentController): Response {
    return $buildExternalContentController()->listExternalPosts($request);
});
$router->get('/api/v1/me/integrations/facebook', function (Request $request, array $params) use ($buildFacebookIntegrationController): Response {
    return $buildFacebookIntegrationController()->status($request);
});
$router->post('/api/v1/me/integrations/facebook/authorize', function (Request $request, array $params) use ($buildFacebookIntegrationController): Response {
    return $buildFacebookIntegrationController()->authorize($request);
});
$router->get('/api/v1/integrations/facebook/callback', function (Request $request, array $params) use ($buildFacebookIntegrationController): Response {
    return $buildFacebookIntegrationController()->callback($request);
});
$router->delete('/api/v1/me/integrations/facebook/{accountId}', function (Request $request, array $params) use ($buildFacebookIntegrationController): Response {
    return $buildFacebookIntegrationController()->disconnect($request,$params);
});
$router->get('/api/v1/me/integrations/linkedin', function(Request $request,array $params)use($buildLinkedInIntegrationController):Response{return $buildLinkedInIntegrationController()->status($request);});
$router->post('/api/v1/me/integrations/linkedin/authorize', function(Request $request,array $params)use($buildLinkedInIntegrationController):Response{return $buildLinkedInIntegrationController()->authorize($request);});
$router->get('/api/v1/integrations/linkedin/callback', function(Request $request,array $params)use($buildLinkedInIntegrationController):Response{return $buildLinkedInIntegrationController()->callback($request);});
$router->delete('/api/v1/me/integrations/linkedin/{accountId}', function(Request $request,array $params)use($buildLinkedInIntegrationController):Response{return $buildLinkedInIntegrationController()->disconnect($request,$params);});

$router->get('/api/v1/me/feed-sources', function (Request $request, array $params) use ($buildExternalContentController): Response {
    return $buildExternalContentController()->listFeedSources($request);
});

$router->post('/api/v1/me/feed-sources', function (Request $request, array $params) use ($buildExternalContentController): Response {
    return $buildExternalContentController()->addFeedSource($request);
});

$router->post('/api/v1/me/sync', function (Request $request, array $params) use ($buildExternalContentController): Response {
    return $buildExternalContentController()->triggerSync($request);
});

$router->get('/api/v1/me/external/stats', function (Request $request, array $params) use ($buildExternalContentController): Response {
    return $buildExternalContentController()->stats($request);
});
$router->get('/api/v1/products', function (Request $request, array $params) use ($buildMarketplaceController): Response {
    return $buildMarketplaceController()->listProducts($request);
});

$router->post('/api/v1/products', function (Request $request, array $params) use ($buildMarketplaceController): Response {
    return $buildMarketplaceController()->createProduct($request);
});

$router->get('/api/v1/products/{productId}', function (Request $request, array $params) use ($buildMarketplaceController): Response {
    return $buildMarketplaceController()->showProduct($request, $params);
});

$router->patch('/api/v1/products/{productId}', function (Request $request, array $params) use ($buildMarketplaceController): Response {
    return $buildMarketplaceController()->updateProduct($request, $params);
});

$router->get('/api/v1/orders', function (Request $request, array $params) use ($buildMarketplaceController): Response {
    return $buildMarketplaceController()->listOrders($request);
});

$router->post('/api/v1/orders', function (Request $request, array $params) use ($buildMarketplaceController): Response {
    return $buildMarketplaceController()->createOrder($request);
});

$router->get('/api/v1/orders/{orderId}', function (Request $request, array $params) use ($buildMarketplaceController): Response {
    return $buildMarketplaceController()->showOrder($request, $params);
});

$router->patch('/api/v1/orders/{orderId}/status', function (Request $request, array $params) use ($buildMarketplaceController): Response {
    return $buildMarketplaceController()->updateOrderStatus($request, $params);
});

$router->get('/api/v1/products/{productId}/download', function (Request $request, array $params) use ($buildMarketplaceController): Response {
    return $buildMarketplaceController()->getDigitalDownload($request, $params);
});
$router->post('/api/v1/federation/send-follow', function (Request $request, array $params) use ($buildFederationController): Response {
    return $buildFederationController()->sendFollow($request);
});

$router->post('/api/v1/federation/send-undo', function (Request $request, array $params) use ($buildFederationController): Response {
    return $buildFederationController()->sendUndo($request);
});

$router->post('/api/v1/federation/send-block', function (Request $request, array $params) use ($buildFederationController): Response {
    return $buildFederationController()->sendBlock($request);
});

$router->post('/api/v1/federation/process-follow', function (Request $request, array $params) use ($buildFederationController): Response {
    return $buildFederationController()->processFollow($request);
});

$router->post('/api/v1/federation/process-undo', function (Request $request, array $params) use ($buildFederationController): Response {
    return $buildFederationController()->processUndo($request);
});
return $router;
