<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Core\Router;

$router = new Router();

$router->get('/api/v1/health', function (Request $request, array $params): Response {
    return Response::json(['data' => ['status' => 'OK']]);
});

$router->get('/profiles/{handle}', function (Request $request, array $params): Response {
    return Response::json(['data' => $params]);
});

$router->get('/@{handle}', function (Request $request, array $params): Response {
    return Response::json(['data' => $params]);
});

$health = $router->dispatch(new Request('GET', '/api/v1/health'));
if ($health->status !== 200 || !str_contains($health->body, '"OK"')) {
    fwrite(STDERR, "Static route dispatch failed\n");
    exit(1);
}

$profile = $router->dispatch(new Request('GET', '/profiles/alice'));
$decoded = json_decode($profile->body, true);
if (($decoded['data']['handle'] ?? null) !== 'alice') {
    fwrite(STDERR, "Dynamic route param matching failed\n");
    exit(1);
}

$atProfile = $router->dispatch(new Request('GET', '/@alice'));
$decodedAtProfile = json_decode($atProfile->body, true);
if (($decodedAtProfile['data']['handle'] ?? null) !== 'alice') {
    fwrite(STDERR, "Embedded route param matching failed\n");
    exit(1);
}

$wrongMethod = $router->dispatch(new Request('POST', '/api/v1/health'));
if ($wrongMethod->status !== 404) {
    fwrite(STDERR, "Unregistered method did not fall back to 404\n");
    exit(1);
}

$missing = $router->dispatch(new Request('GET', '/not-registered'));
if ($missing->status !== 404 || !str_contains($missing->body, 'NOT_FOUND')) {
    fwrite(STDERR, "Missing route did not return a NOT_FOUND envelope\n");
    exit(1);
}

fwrite(STDOUT, "Router test passed\n");
