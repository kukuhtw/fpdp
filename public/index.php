<?php

declare(strict_types=1);

use App\Core\Config;
use App\Core\Http\JsonEnvelope;
use App\Core\Http\Request;
use App\Core\Router;

require_once __DIR__ . '/../vendor/autoload.php';

// This is a JSON API: a PHP warning/notice's inline HTML output (from
// display_errors, whatever the host's php.ini default is) must never leak
// into a response body — it corrupts the JSON and every client-side
// JSON.parse() breaks on it ("Unexpected token '<'"). Promoting every
// warning/notice to an exception routes it through the try/catch below
// instead, so the client always gets a clean JSON error; the original
// message is still available via error_log(), never echoed to the client
// unless APP_DEBUG is on.
ini_set('display_errors', '0');
set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
    if (!(error_reporting() & $severity)) {
        return false;
    }
    throw new \ErrorException($message, 0, $severity, $file, $line);
});

$debug = false;

try {
    Config::load(__DIR__ . '/../.env');
    $debug = Config::getBool('APP_DEBUG');

    /** @var Router $router */
    $router = require __DIR__ . '/../app/routes.php';

    $response = $router->dispatch(Request::fromGlobals());
} catch (\Throwable $e) {
    // Whatever the client sees (generic in production, since APP_DEBUG
    // hides internals from the response for good reason), the real
    // exception must still be logged somewhere — otherwise a 500 is a
    // complete black box with no trace of what broke.
    error_log(sprintf(
        '[UNCAUGHT] %s: %s in %s:%d%s%s',
        get_class($e),
        $e->getMessage(),
        $e->getFile(),
        $e->getLine(),
        "\n",
        $e->getTraceAsString(),
    ));
    $message = $debug ? $e->getMessage() : 'An unexpected error occurred.';
    $response = JsonEnvelope::error('INTERNAL_ERROR', $message, 500);
}

$response->send();
