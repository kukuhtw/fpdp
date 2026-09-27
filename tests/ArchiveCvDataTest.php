<?php

declare(strict_types=1);

/**
 * scripts/archive-cv-data.php: summary changes nothing, --archive writes a
 * verified archive of the old CV tables and files, --delete only runs
 * against an archive that still matches, then removes tables and files.
 */
function acv_assert(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

$work = sys_get_temp_dir() . '/fpdp-cv-archive-test-' . uniqid();
$storage = "{$work}/storage";
mkdir("{$storage}/cv", 0777, true);
$dbPath = "{$work}/test.sqlite";

$db = new PDO("sqlite:{$dbPath}");
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
foreach ([
    'CREATE TABLE nodes (id INTEGER PRIMARY KEY AUTOINCREMENT, public_id TEXT, domain TEXT, name TEXT, default_locale TEXT, timezone TEXT, status TEXT, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP)',
    'CREATE TABLE audit_events (id INTEGER PRIMARY KEY AUTOINCREMENT, node_id INTEGER NOT NULL, actor_user_id INTEGER, action TEXT NOT NULL, subject_type TEXT, subject_public_id TEXT, metadata TEXT, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP)',
    // As left by migration 0066.
    'CREATE TABLE archived_cv_documents (id INTEGER PRIMARY KEY AUTOINCREMENT, public_id TEXT, node_id INTEGER, title TEXT, storage_key TEXT, content_type TEXT, price_amount TEXT, price_currency TEXT, status TEXT)',
    'CREATE TABLE archived_cv_access_grants (id INTEGER PRIMARY KEY AUTOINCREMENT, cv_document_id INTEGER, visitor_id INTEGER, payment_reference TEXT, granted_at TIMESTAMP)',
] as $sql) {
    $db->exec($sql);
}
$db->exec("INSERT INTO nodes (public_id, domain, name) VALUES ('n1', 'test.local', 'Node')");
$db->exec("INSERT INTO archived_cv_documents (public_id, node_id, title, storage_key, content_type, price_amount, price_currency, status) VALUES ('d1', 1, 'Résumé Siti', 'abc.pdf', 'application/pdf', '50000.00', 'IDR', 'ACTIVE')");
$db->exec("INSERT INTO archived_cv_access_grants (cv_document_id, visitor_id, payment_reference, granted_at) VALUES (1, 7, 'CV-1-7-1', '2026-09-01 10:00:00'), (1, 8, 'CV-1-8-2', '2026-09-02 11:00:00')");
file_put_contents("{$storage}/cv/abc.pdf", '%PDF-1.4 resume of Siti');
mkdir("{$storage}/cv/old", 0777, true);
file_put_contents("{$storage}/cv/old/replaced.pdf", '%PDF-1.4 an older upload');
unset($db);

$run = static function (string $arguments) use ($dbPath, $storage): array {
    $env = ['DB_CONNECTION' => 'sqlite', 'DB_DATABASE' => $dbPath, 'APP_ENV' => 'testing', 'APP_KEY' => str_repeat('k', 64), 'NODE_DOMAIN' => 'test.local'] + getenv();
    $command = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(dirname(__DIR__) . '/scripts/archive-cv-data.php') . ' --storage-dir=' . escapeshellarg($storage) . ' ' . $arguments;
    $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $env);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);

    return ['code' => proc_close($process), 'out' => $stdout . $stderr];
};
$count = static function (string $sql) use ($dbPath): int {
    $db = new PDO("sqlite:{$dbPath}");

    return (int) $db->query($sql)->fetchColumn();
};
$tableCount = static fn (): int => $count("SELECT COUNT(*) FROM sqlite_master WHERE type = 'table' AND name LIKE 'archived_cv_%'");

// ---- 1. Summary only: nothing changes ----
$summary = $run('');
acv_assert($summary['code'] === 0, 'summary run: ' . $summary['out']);
acv_assert(str_contains($summary['out'], 'CV documents: 1 row(s) in archived_cv_documents') && str_contains($summary['out'], 'CV access grants: 2 row(s)') && str_contains($summary['out'], 'Files in') && str_contains($summary['out'], ': 2 ('), 'summary text: ' . $summary['out']);
acv_assert($tableCount() === 2 && is_file("{$storage}/cv/abc.pdf"), 'the summary changes nothing');

// ---- 2. Delete without an archive / without --confirm is refused ----
acv_assert($run('--delete --confirm')['code'] === 1, 'delete without an archive is refused');
acv_assert($tableCount() === 2, 'still nothing deleted');

// ---- 3. Archive: written, private, complete, verified ----
$archive = $run('--archive');
acv_assert($archive['code'] === 0 && str_contains($archive['out'], 'Archive written and verified'), 'archive run: ' . $archive['out']);
$files = glob("{$storage}/archive/cv-archive-*.tar.gz");
acv_assert(count($files) === 1 && !glob("{$storage}/archive/*.tar"), 'one .tar.gz archive, no leftover .tar');
$archivePath = $files[0];
if (DIRECTORY_SEPARATOR === '/') {
    acv_assert((fileperms($archivePath) & 0777) === 0600, 'the archive is readable by its owner only');
}
$base = 'phar://' . str_replace('\\', '/', realpath($archivePath));
$manifest = json_decode(file_get_contents("{$base}/manifest.json"), true);
acv_assert($manifest['tables']['cv_documents']['rows'] === 1 && $manifest['tables']['cv_access_grants']['rows'] === 2, 'row counts in the manifest');
acv_assert(json_decode(file_get_contents("{$base}/cv_documents.json"), true)[0]['title'] === 'Résumé Siti', 'rows archived intact (UTF-8)');
acv_assert(file_get_contents("{$base}/files/abc.pdf") === '%PDF-1.4 resume of Siti' && file_get_contents("{$base}/files/old/replaced.pdf") === '%PDF-1.4 an older upload', 'files archived, including subfolders');
acv_assert($tableCount() === 2 && is_file("{$storage}/cv/abc.pdf"), 'archiving deletes nothing');

// ---- 4. Delete needs --confirm, and refuses if the data changed after archiving ----
acv_assert($run('--delete --archive-file=' . escapeshellarg($archivePath))['code'] === 1, 'delete without --confirm is refused');
file_put_contents("{$storage}/cv/late.pdf", 'uploaded after the archive');
$changed = $run('--delete --confirm --archive-file=' . escapeshellarg($archivePath));
acv_assert($changed['code'] === 1 && str_contains($changed['out'], 'changed since the archive'), 'a file added after archiving blocks the delete: ' . $changed['out']);
unlink("{$storage}/cv/late.pdf");
(new PDO("sqlite:{$dbPath}"))->exec("INSERT INTO archived_cv_access_grants (cv_document_id, visitor_id) VALUES (1, 9)");
acv_assert($run('--delete --confirm --archive-file=' . escapeshellarg($archivePath))['code'] === 1, 'a row added after archiving blocks the delete');
(new PDO("sqlite:{$dbPath}"))->exec('DELETE FROM archived_cv_access_grants WHERE visitor_id = 9');

// ---- 5. A tampered archive is refused ----
$tampered = "{$storage}/archive/tampered.tar";
$copy = new PharData($tampered);
$copy->addFromString('manifest.json', file_get_contents("{$base}/manifest.json"));
$copy->addFromString('cv_documents.json', '[]');
$copy->addFromString('cv_access_grants.json', file_get_contents("{$base}/cv_access_grants.json"));
unset($copy);
acv_assert($run('--delete --confirm --archive-file=' . escapeshellarg($tampered))['code'] === 1, 'an archive that fails its own manifest is refused');
acv_assert($tableCount() === 2, 'nothing deleted by refused runs');

// ---- 6. Delete with the matching archive ----
$deleted = $run('--delete --confirm --archive-file=' . escapeshellarg($archivePath));
acv_assert($deleted['code'] === 0, 'delete run: ' . $deleted['out']);
acv_assert($tableCount() === 0, 'both tables dropped');
acv_assert(!is_dir("{$storage}/cv"), 'storage/cv removed');
acv_assert(is_file($archivePath), 'the archive itself is kept');
$actions = (new PDO("sqlite:{$dbPath}"))->query('SELECT action, metadata FROM audit_events ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
acv_assert(array_column($actions, 'action') === ['cv_data.archived', 'cv_data.deleted'], 'archive and delete are audited: ' . json_encode($actions));
acv_assert(!str_contains(json_encode($actions), 'Siti'), 'the audit holds counts, not personal data');

// ---- 7. Running again afterwards is harmless ----
$again = $run('');
acv_assert($again['code'] === 0 && str_contains($again['out'], '(no table)'), 'a later summary reports nothing left: ' . $again['out']);

// Cleanup
$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($work, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
foreach ($iterator as $item) {
    $item->isDir() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
}
@rmdir($work);
fwrite(STDOUT, "ArchiveCvDataTest passed\n");
