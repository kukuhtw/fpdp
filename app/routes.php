<?php

declare(strict_types=1);

use App\Controllers\AuthController;
use App\Controllers\CvController;
use App\Controllers\HealthController;
use App\Controllers\HomeController;
use App\Controllers\ProfileController;
use App\Controllers\VisitorAuthController;
use App\Core\Config;
use App\Core\Database;
use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Core\Router;
use App\Repositories\AuthTokenRepository;
use App\Repositories\CvAccessGrantRepository;
use App\Repositories\CvDocumentRepository;
use App\Repositories\NodeRepository;
use App\Repositories\ProfileRepository;
use App\Repositories\RateLimitRepository;
use App\Repositories\UserRepository;
use App\Repositories\VisitorRepository;
use App\Repositories\VisitorTokenRepository;
use App\Services\Auth\AuthService;
use App\Services\Cv\CvAccessService;
use App\Services\Cv\CvDocumentService;
use App\Services\Payment\PaymentService;
use App\Services\Profile\ProfileService;
use App\Services\Security\RateLimiter;
use App\Services\Visitor\GoogleOAuthClient;
use App\Services\Visitor\OAuthStateSigner;
use App\Services\Visitor\VisitorAuthService;

$router = new Router();

// Database connections are created lazily inside each closure so that
// routes with no persistence needs (/, /api/v1/health) never touch the DB.
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

$buildRateLimiter = static function (): RateLimiter {
    return new RateLimiter(new RateLimitRepository(Database::connection()));
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

$buildCvController = static function () use ($buildAuthService, $buildVisitorAuthService, $cvStorageDirectory): CvController {
    $connection = Database::connection();

    return new CvController(
        $buildAuthService(),
        new ProfileService(new ProfileRepository($connection)),
        $buildVisitorAuthService(),
        new CvDocumentService(new CvDocumentRepository($connection), new CvAccessGrantRepository($connection), $cvStorageDirectory),
        new CvAccessService(
            new CvDocumentRepository($connection),
            new CvAccessGrantRepository($connection),
            new PaymentService(),
            $cvStorageDirectory,
        ),
    );
};

$router->get('/', function (Request $request, array $params): Response {
    return Response::html((new HomeController())->index());
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

$router->get('/api/v1/profiles/{handle}', function (Request $request, array $params) use ($buildAuthService, $buildProfileService): Response {
    return (new ProfileController($buildAuthService(), $buildProfileService()))->show($request, $params);
});

$router->patch('/api/v1/me/profile', function (Request $request, array $params) use ($buildAuthService, $buildProfileService): Response {
    return (new ProfileController($buildAuthService(), $buildProfileService()))->update($request);
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

return $router;
