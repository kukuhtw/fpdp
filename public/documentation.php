<?php

declare(strict_types=1);

/**
 * Documentation renderer for /documentation/* paths.
 *
 * Reads markdown files from the project's documentation/ directory,
 * parses basic Markdown to HTML, and renders them in a consistent
 * Bootstrap 5 layout with a sidebar table of contents.
 */

require_once __DIR__ . '/../vendor/autoload.php';

// ---- Resolve the requested documentation path ----
$requestUri = $_SERVER['REQUEST_URI'] ?? '/';
$path = parse_url($requestUri, PHP_URL_PATH);
$relativePath = preg_replace('#^/documentation/#', '', $path);
$relativePath = trim($relativePath, '/');

if ($relativePath === '' || $relativePath === 'index') {
    $relativePath = 'README.md';
}

// Security: prevent directory traversal
$relativePath = str_replace('..', '', $relativePath);
$relativePath = ltrim($relativePath, '/');

$docRoot = __DIR__ . '/../documentation';
$fullPath = realpath($docRoot . '/' . $relativePath);