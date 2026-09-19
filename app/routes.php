<?php

declare(strict_types=1);

use App\Controllers\AuthController;
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
use App\Repositories\NodeRepository;
use App\Repositories\ProfileRepository;
use App\Repositories\RateLimitRepository;
use App\Repositories\UserRepository;
use App\Repositories\VisitorRepository;
use App\Repositories\VisitorTokenRepository;
use App\Services\Auth\AuthService;
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

$buildVisitorAuthController = static function (): VisitorAuthController {
    $connection = Database::connection();

    $googleClient = new GoogleOAuthClient(
        Config::get('GOOGLE_CLIENT_ID', ''),
        Config::get('GOOGLE_CLIENT_SECRET', ''),
    );

    $visitorAuth = new VisitorAuthService(
        $googleClient,
        new VisitorRepository($connection),
        new VisitorTokenRepository($connection),
    );

    $stateSigner = new OAuthStateSigner(Config::get('APP_KEY', ''));

    return new VisitorAuthController(
        new ProfileService(new ProfileRepository($connection)),
        $visitorAuth,
        $stateSigner,
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

return $router;
