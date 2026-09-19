<?php

declare(strict_types=1);

use App\Core\Config;
use App\Core\Http\JsonEnvelope;
use App\Core\Http\Request;
use App\Core\Router;

require_once __DIR__ . '/../vendor/autoload.php';

$debug = false;

try {
    Config::load(__DIR__ . '/../.env');
    $debug = Config::getBool('APP_DEBUG');

    /** @var Router $router */
    $router = require __DIR__ . '/../app/routes.php';

    $response = $router->dispatch(Request::fromGlobals());
} catch (\Throwable $e) {
    $message = $debug ? $e->getMessage() : 'An unexpected error occurred.';
    $response = JsonEnvelope::error('INTERNAL_ERROR', $message, 500);
}

$response->send();
