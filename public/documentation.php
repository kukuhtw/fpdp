<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

$uri = $_SERVER['REQUEST_URI'] ?? '/';
$path = parse_url($uri, PHP_URL_PATH);
$file = preg_replace('#^/documentation/#', '', $path);
$file = ltrim($file, '/');
if ($file === '' || stripos($file, 'index') === 0) $file = 'README.md';
$file = str_replace('..', '', $file);

$docRoot = __DIR__ . '/../documentation';
$fullPath = realpath($docRoot . '/' . $file);
$docReal = realpath($docRoot);
if ($fullPath === false || $docReal === false || !str_starts_with($fullPath, $docReal)) {
    http_response_code(404);
    echo '<html><body><h1>404</h1></body></html>';
    exit;
}

$content = file_get_contents($fullPath);
if ($content === false) { http_response_code(500); exit; }

$lang = preg_match('/\.id\.md$/', $file) ? 'id' : 'en';

// Markdown parsing
$html = ''; $lines = explode("\n", $content); $i = 0; $inCode = false; $codeBuf = ''; $codeLang = '';
while ($i < count($lines)) {
    $line = $lines[$i];
    if (str_starts_with($line, '```')) {
        if ($inCode) {
            $html .= '<pre><code class="language-' . htmlspecialchars($codeLang) . '">' . htmlspecialchars($codeBuf) . '</code></pre>';
            $inCode = false; $codeBuf = ''; $codeLang = ''; $i++; continue;
        }
        $inCode = true; $codeLang = trim(substr($line, 3)); $i++; continue;
    }
    if ($inCode) { $codeBuf .= $line . "\n"; $i++; continue; }
    if (preg_match('/^(#{1,6})\s+(.+)$/', $line, $m)) {
        $lvl = strlen($m[1]); $text = htmlspecialchars($m[2]);
        $html .= "<h{$lvl}>{$text}</h{$lvl}>"; $i++; continue;
    }
    if (trim($line) === '') { $i++; continue; }
    $text = htmlspecialchars($line);
    $text = preg_replace('/\*\*(.+?)\*\*/', '<strong>$1</strong>', $text);
    $text = preg_replace('/\*(.+?)\*/', '<em>$1</em>', $text);
    $text = preg_replace('/`(.+?)`/', '<code>$1</code>', $text);
    $text = preg_replace_callback('/\[(.+?)\]\((.+?)\)/', function ($m) {
        $url = $m[2];
        if (!preg_match('/^https?:\/\//', $url) && str_ends_with($url, '.md')) $url = '/documentation/' . ltrim($url, '/');
        $target = preg_match('/^https?:\/\//', $url) ? ' target="_blank" rel="noopener"' : '';
        return '<a href="' . htmlspecialchars($url) . '"' . $target . '>' . htmlspecialchars($m[1]) . '</a>';
    }, $text);
    $html .= "<p>{$text}</p>"; $i++;
}

$title = 'FPDP Documentation';
if (preg_match('/<h1>(.*?)<\/h1>/', $html, $m)) $title = strip_tags($m[1]) . ' · FPDP';

$files = [];
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($docRoot, RecursiveDirectoryIterator::SKIP_DOTS));
foreach ($it as $fn) {
    if (in_array($fn->getExtension(), ['md', 'yaml', 'yml'])) {
        $rel = str_replace($docRoot . '/', '', $fn->getPathname());
        $files[] = ['path' => $rel, 'name' => basename($rel), 'dir' => dirname($rel)];
    }
}
sort($files);