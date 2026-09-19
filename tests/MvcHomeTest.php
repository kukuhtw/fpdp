<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use App\Controllers\HomeController;

$controller = new HomeController();
$html = $controller->index();

if (!str_contains($html, 'FPDP') || !str_contains($html, 'Personal Digital Home')) {
    fwrite(STDERR, "Home page render failed\n");
    exit(1);
}

fwrite(STDOUT, "MVC home render passed\n");
