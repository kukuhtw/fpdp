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
?><!DOCTYPE html>
<html lang="<?=htmlspecialchars($lang)?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?=htmlspecialchars($title)?></title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<style>
body{background:#f5f7fa}
.doc-sidebar{position:sticky;top:1rem;max-height:calc(100vh-2rem);overflow-y:auto;font-size:.875rem}
.doc-sidebar .nav-link{color:#495057;padding:.25rem .75rem;border-radius:.25rem;text-decoration:none;display:block}
.doc-sidebar .nav-link:hover,.doc-sidebar .nav-link.active{background:#0d6efd;color:#fff}
.doc-content{background:#fff;border-radius:.5rem;padding:2rem;box-shadow:0 1px 3px rgba(0,0,0,.08)}
.doc-content h1{border-bottom:2px solid #dee2e6;padding-bottom:.5rem}
.doc-content h2{border-bottom:1px solid #eee;padding-bottom:.375rem;margin-top:2rem}
.doc-content img{max-width:100%}
.doc-content table{width:100%;border-collapse:collapse;margin:1rem 0}
.doc-content th,.doc-content td{border:1px solid #dee2e6;padding:.5rem .75rem}
.doc-content th{background:#f8f9fa}
.doc-content pre{background:#f6f8fa;border-radius:.375rem;padding:1rem;overflow-x:auto}
.doc-content code{background:#f0f1f3;padding:.125rem .375rem;border-radius:.25rem;font-size:.875em}
.doc-content blockquote{border-left:4px solid #0d6efd;padding:.5rem 1rem;margin:1rem 0;background:#f8f9ff}
@media(max-width:767.98px){.doc-sidebar{position:static;max-height:none}}
</style>
</head>
<body>
<nav class="navbar navbar-expand-lg navbar-dark bg-dark"><div class="container">
<a class="navbar-brand" href="/">FPDP</a>
<ul class="navbar-nav ms-auto">
<li class="nav-item"><a class="nav-link" href="/documentation/README.md">Docs</a></li>
<li class="nav-item"><a class="nav-link" href="/">Site</a></li>
</ul></div></nav>
<div class="container-fluid py-4"><div class="row g-4">
<aside class="col-lg-2 col-md-3"><div class="doc-sidebar">
<div class="mb-3"><a href="/documentation/README.md" class="btn btn-outline-primary btn-sm w-100">Home</a></div>
<?php $curDir='';foreach($files as$df){if(str_ends_with($df['name'],'.id.md')&&$lang==='en')continue;if(!str_ends_with($df['name'],'.id.md')&&$lang==='id'&&!str_ends_with($df['name'],'.en.md')&&$df['name']!=='README.md')continue;
if($df['dir']!==$curDir){$curDir=$df['dir'];echo'<div class="fw-bold text-uppercase small text-muted px-2 mt-2 mb-1">'.htmlspecialchars(ucfirst(basename($curDir))).'</div>';}
$lbl=preg_replace('/\.(en|id)\.md$/','',$df['name']);$lbl=preg_replace('/\.md$/','',$lbl);$lbl=str_replace('-',' ',$lbl);$lbl=ucwords($lbl);
echo'<a class="nav-link'.($file===$df['path']?' active':'').'" href="/documentation/'.htmlspecialchars($df['path']).'">'.htmlspecialchars($lbl).'</a>';}?>
</div></aside>
<main class="col-lg-10 col-md-9"><article class="doc-content"><?=$html?>
<div class="mt-4 pt-3 border-top text-muted small"><a href="https://github.com/kukuhtw/fpdp/blob/main/documentation/<?=htmlspecialchars($file)?>" target="_blank" rel="noopener">Edit on GitHub</a></div>
</article></main></div></div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body></html>