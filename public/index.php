<?php

declare(strict_types=1);

use App\Core\Http\Request;
use App\Core\Router;

require_once __DIR__ . '/../vendor/autoload.php';

/** @var Router $router */
$router = require __DIR__ . '/../app/routes.php';

$router->dispatch(Request::fromGlobals())->send();
