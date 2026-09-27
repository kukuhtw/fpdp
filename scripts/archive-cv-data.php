#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Archive, then delete, what is left of the removed CV/resume feature: the
 * cv_documents / cv_access_grants tables (renamed archived_cv_* by migration
 * 0066) and the uploaded files in storage/cv/. They hold buyer identity, so
 * deletion (ISO/IEC 27001:2022 A.8.10) only happens against a verified
 * archive that still matches the data exactly.
 *
 *   php scripts/archive-cv-data.php
 *       Summary only: what exists. Changes nothing.
 *
 *   php scripts/archive-cv-data.php --archive [--output-dir=DIR]
 *       Writes storage/archive/cv-archive-<time>.tar.gz (chmod 600): the
 *       table rows as JSON, the files, and a manifest of SHA-256 hashes,
 *       then re-opens it and verifies every entry. Changes no data.
 *
 *   php scripts/archive-cv-data.php --delete --archive-file=PATH --confirm
 *       Verifies the archive again, checks it still matches the current
 *       rows and files exactly, then drops the tables and deletes
 *       storage/cv/. Refuses if anything differs. Cannot be undone.
 *
 * The archive contains personal data: move it off the server (encrypted,
 * e.g. `gpg -c`), keep it only as long as needed, then delete it.
 *
 * Options: --storage-dir=DIR (default: ./storage)
 */

require_once __DIR__ . '/../vendor/autoload.php';

use App\Core\Config;
use App\Core\Database;
use App\Repositories\AuditEventRepository;
use App\Repositories\NodeRepository;
use App\Services\Security\AuditService;

Config::load(dirname(__DIR__) . '/.env');

$options = getopt('', ['archive', 'delete', 'confirm', 'archive-file:', 'output-dir:', 'storage-dir:']);
$storageDir = rtrim((string) ($options['storage-dir'] ?? dirname(__DIR__) . '/storage'), '/\\');
$cvDir = $storageDir . '/cv';
$outputDir = rtrim((string) ($options['output-dir'] ?? $storageDir . '/archive'), '/\\');
$db = Database::connection();

$out = static fn (string $line) => fwrite(STDOUT, $line . "\n");
$fail = static function (string $line): never {
    fwrite(STDERR, "[archive-cv-data] {$line}\n");
    exit(1);
};

$tableExists = static function (string $name) use ($db): bool {
    if ($db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') {
        $statement = $db->prepare("SELECT COUNT(*) FROM sqlite_master WHERE type = 'table' AND name = ?");
    } else {
        $statement = $db->prepare('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?');
    }
    $statement->execute([$name]);

    return (int) $statement->fetchColumn() > 0;
};
// After migration 0066 the tables are archived_cv_*; before it, cv_*.
$resolve = static function (string $base) use ($tableExists): ?string {
    foreach (["archived_{$base}", $base] as $name) {
        if ($tableExists($name)) {
            return $name;
        }
    }

    return null;
};

/**
 * The current state, serialized exactly the way it goes into the archive.
 *
 * @return array{tables: array<string, array{table: string|null, rows: int, json: string}>, files: array<string, array{size: int, sha256: string}>}
 */
$snapshot = static function () use ($db, $resolve, $cvDir): array {
    $tables = [];
    foreach (['cv_documents', 'cv_access_grants'] as $base) {
        $table = $resolve($base);
        $rows = $table === null ? [] : $db->query("SELECT * FROM {$table} ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
        $tables[$base] = ['table' => $table, 'rows' => count($rows), 'json' => json_encode($rows, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)];
    }

    $files = [];
    if (is_dir($cvDir)) {
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($cvDir, FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if ($file->isFile()) {
                $relative = str_replace('\\', '/', substr($file->getPathname(), strlen($cvDir) + 1));
                $files[$relative] = ['size' => $file->getSize(), 'sha256' => hash_file('sha256', $file->getPathname())];
            }
        }
        ksort($files);
    }

    return ['tables' => $tables, 'files' => $files];
};

/**
 * Re-reads an archive and checks every entry against its manifest.
 *
 * @return array<string, mixed> the manifest
 */
$verifyArchive = static function (string $path) use ($fail): array {
    if (!is_file($path)) {
        $fail("Archive not found: {$path}");
    }
    $base = 'phar://' . str_replace('\\', '/', (string) realpath($path));
    $manifest = json_decode((string) @file_get_contents("{$base}/manifest.json"), true);
    if (!is_array($manifest) || ($manifest['format'] ?? null) !== 'fpdp-cv-archive/1') {
        $fail("Not a CV archive, or it cannot be read: {$path}");
    }
    foreach ($manifest['tables'] as $base_ => $info) {
        $json = @file_get_contents("{$base}/{$base_}.json");
        if ($json === false || hash('sha256', $json) !== $info['sha256'] || count(json_decode($json, true) ?? []) !== $info['rows']) {
            $fail("Archive check failed for {$base_}.json");
        }
    }
    foreach ($manifest['files'] as $relative => $info) {
        $content = @file_get_contents("{$base}/files/{$relative}");
        if ($content === false || strlen($content) !== $info['size'] || hash('sha256', $content) !== $info['sha256']) {
            $fail("Archive check failed for files/{$relative}");
        }
    }

    return $manifest;
};

$audit = static function (string $action, array $metadata) use ($db): void {
    $node = (new NodeRepository($db))->findFirst();
    if ($node !== null) {
        (new AuditService(new AuditEventRepository($db)))->record(['user' => null, 'node' => $node], $action, 'cv_data', null, $metadata + ['via' => 'cli']);
    }
};

$current = $snapshot();
$bytes = array_sum(array_column($current['files'], 'size'));
$out(sprintf('CV documents: %d row(s) in %s', $current['tables']['cv_documents']['rows'], $current['tables']['cv_documents']['table'] ?? '(no table)'));
$out(sprintf('CV access grants: %d row(s) in %s', $current['tables']['cv_access_grants']['rows'], $current['tables']['cv_access_grants']['table'] ?? '(no table)'));
$out(sprintf('Files in %s: %d (%s bytes)', $cvDir, count($current['files']), number_format($bytes)));

if (!isset($options['archive']) && !isset($options['delete'])) {
    $out('Nothing changed. Next: --archive, then --delete --archive-file=... --confirm.');
    exit(0);
}

if (isset($options['archive'])) {
    if (!is_dir($outputDir) && !mkdir($outputDir, 0700, true) && !is_dir($outputDir)) {
        $fail("Cannot create {$outputDir}");
    }
    $stem = $outputDir . '/cv-archive-' . date('Ymd-His');
    if (file_exists("{$stem}.tar") || file_exists("{$stem}.tar.gz")) {
        $fail("{$stem}.tar.gz already exists; try again in a second.");
    }

    $manifest = [
        'format' => 'fpdp-cv-archive/1',
        'created_at' => date('c'),
        'note' => 'Personal data (buyer identity). Store encrypted, off the server, only as long as needed.',
        'tables' => [],
        'files' => $current['files'],
    ];
    $tar = new PharData("{$stem}.tar");
    foreach ($current['tables'] as $base => $info) {
        $tar->addFromString("{$base}.json", $info['json']);
        $manifest['tables'][$base] = ['source_table' => $info['table'], 'rows' => $info['rows'], 'sha256' => hash('sha256', $info['json'])];
    }
    foreach (array_keys($current['files']) as $relative) {
        $tar->addFile("{$cvDir}/{$relative}", "files/{$relative}");
    }
    $tar->addFromString('manifest.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    $tar->compress(Phar::GZ);
    unset($tar);
    @unlink("{$stem}.tar");
    $archivePath = "{$stem}.tar.gz";
    @chmod($archivePath, 0600);

    $verifyArchive($archivePath);
    $audit('cv_data.archived', ['archive' => basename($archivePath), 'documents' => $current['tables']['cv_documents']['rows'], 'grants' => $current['tables']['cv_access_grants']['rows'], 'files' => count($current['files'])]);
    $out("Archive written and verified: {$archivePath}");
    $out('It contains personal data: move it off the server (e.g. gpg -c), then delete the copy here.');
    $out("Next: php scripts/archive-cv-data.php --delete --archive-file={$archivePath} --confirm");
    exit(0);
}

// --delete
$archiveFile = (string) ($options['archive-file'] ?? '');
if ($archiveFile === '' || !isset($options['confirm'])) {
    $fail('Deleting needs --archive-file=PATH (from --archive) and --confirm. It cannot be undone.');
}
$manifest = $verifyArchive($archiveFile);

// The archive must hold exactly what is about to be deleted.
foreach ($current['tables'] as $base => $info) {
    if ($manifest['tables'][$base]['rows'] !== $info['rows'] || $manifest['tables'][$base]['sha256'] !== hash('sha256', $info['json'])) {
        $fail("{$base} changed since the archive was made. Run --archive again.");
    }
}
if ($manifest['files'] != $current['files']) {
    $fail("The files in {$cvDir} changed since the archive was made. Run --archive again.");
}

// Grants reference documents: drop them first.
foreach (['cv_access_grants', 'cv_documents'] as $base) {
    $table = $current['tables'][$base]['table'];
    if ($table !== null) {
        $db->exec("DROP TABLE {$table}");
    }
}
if (is_dir($cvDir)) {
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($cvDir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($iterator as $item) {
        $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
    }
    rmdir($cvDir);
}

$audit('cv_data.deleted', ['archive' => basename($archiveFile), 'documents' => $current['tables']['cv_documents']['rows'], 'grants' => $current['tables']['cv_access_grants']['rows'], 'files' => count($current['files'])]);
$out('CV tables dropped and storage/cv deleted. Keep or destroy the archive per your retention rule.');
