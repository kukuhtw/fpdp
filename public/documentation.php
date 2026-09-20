<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

// ---- Resolve request ----
$requestUri = $_SERVER['REQUEST_URI'] ?? '/';
$path = parse_url($requestUri, PHP_URL_PATH);
$relativePath = preg_replace('#^/documentation/#', '', $path);
$relativePath = ltrim($relativePath, '/');

if ($relativePath === '' || stripos($relativePath, 'index') === 0) {
    $relativePath = 'README.md';
}

// Security
$relativePath = str_replace('..', '', $relativePath);
$relativePath = ltrim($relativePath, '/');

$docRoot = __DIR__ . '/../documentation';
$fullPath = realpath($docRoot . '/' . $relativePath);