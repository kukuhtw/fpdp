<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use App\Core\Http\Request;
use App\Core\Router;

/** @var Router $router */
$router = require __DIR__ . '/../app/routes.php';

$home = $router->dispatch(new Request('GET', '/'));
if ($home->status !== 200 || !str_contains($home->body, 'Personal Digital Home')) {
    fwrite(STDERR, "Home route failed\n");
    exit(1);
}

$health = $router->dispatch(new Request('GET', '/api/v1/health'));
$decoded = json_decode($health->body, true);
if ($health->status !== 200
    || ($decoded['data']['status'] ?? null) !== 'OK'
    || !isset($decoded['meta']['request_id'])
) {
    fwrite(STDERR, "Health route did not return the documented envelope\n");
    exit(1);
}

$missing = $router->dispatch(new Request('GET', '/does-not-exist'));
if ($missing->status !== 404) {
    fwrite(STDERR, "Unknown route did not return 404\n");
    exit(1);
}

fwrite(STDOUT, "Front controller route test passed\n");
