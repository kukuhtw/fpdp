<?php

declare(strict_types=1);

use App\Controllers\AuthController;
use App\Controllers\HealthController;
use App\Controllers\HomeController;
use App\Controllers\ProfileController;
use App\Core\Database;
use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Core\Router;
use App\Repositories\AuthTokenRepository;
use App\Repositories\NodeRepository;
use App\Repositories\ProfileRepository;
use App\Repositories\UserRepository;
use App\Services\Auth\AuthService;
use App\Services\Profile\ProfileService;

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

$router->get('/', function (Request $request, array $params): Response {
    return Response::html((new HomeController())->index());
});

$router->get('/api/v1/health', function (Request $request, array $params): Response {
    return (new HealthController())->show();
});

$router->post('/api/v1/auth/register', function (Request $request, array $params) use ($buildAuthService): Response {
    return (new AuthController($buildAuthService()))->register($request);
});

$router->post('/api/v1/auth/login', function (Request $request, array $params) use ($buildAuthService): Response {
    return (new AuthController($buildAuthService()))->login($request);
});

$router->post('/api/v1/auth/logout', function (Request $request, array $params) use ($buildAuthService): Response {
    return (new AuthController($buildAuthService()))->logout($request);
});

$router->get('/api/v1/me', function (Request $request, array $params) use ($buildAuthService): Response {
    return (new AuthController($buildAuthService()))->me($request);
});

$router->get('/api/v1/profiles/{handle}', function (Request $request, array $params) use ($buildAuthService, $buildProfileService): Response {
    return (new ProfileController($buildAuthService(), $buildProfileService()))->show($request, $params);
});

$router->patch('/api/v1/me/profile', function (Request $request, array $params) use ($buildAuthService, $buildProfileService): Response {
    return (new ProfileController($buildAuthService(), $buildProfileService()))->update($request);
});

return $router;
