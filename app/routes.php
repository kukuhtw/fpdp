<?php

declare(strict_types=1);

use App\Controllers\HealthController;
use App\Controllers\HomeController;
use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Core\Router;

$router = new Router();

$router->get('/', function (Request $request, array $params): Response {
    return Response::html((new HomeController())->index());
});

$router->get('/api/v1/health', function (Request $request, array $params): Response {
    return (new HealthController())->show();
});

return $router;
