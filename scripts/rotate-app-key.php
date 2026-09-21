<?php

declare(strict_types=1);

use App\Core\Crypto;
use App\Core\Config;
use App\Core\Database;

require dirname(__DIR__) . '/vendor/autoload.php';
Config::load(dirname(__DIR__) . '/.env');

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This command can only run from the CLI.\n");
    exit(1);
}

$oldKey = (string) (getenv('FPDP_OLD_APP_KEY') ?: '');
$newKey = (string) (getenv('FPDP_NEW_APP_KEY') ?: '');
if ($oldKey === '' || $newKey === '' || hash_equals($oldKey, $newKey)) {
    fwrite(STDERR, "Set distinct FPDP_OLD_APP_KEY and FPDP_NEW_APP_KEY environment variables.\n");
    exit(1);
}
if (strlen($newKey) < 32) {
    fwrite(STDERR, "FPDP_NEW_APP_KEY must contain at least 32 characters.\n");
    exit(1);
}

$db = Database::connection();
$targets = [
    ['table' => 'payment_gateway_configs', 'column' => 'encrypted_value'],
    ['table' => 'external_accounts', 'column' => 'access_token'],
];

$db->beginTransaction();
try {
    $updated = 0;
    foreach ($targets as $target) {
        $table = $target['table'];
        $column = $target['column'];
        $rows = $db->query("SELECT id, {$column} FROM {$table} WHERE {$column} IS NOT NULL AND {$column} != ''")->fetchAll(\PDO::FETCH_ASSOC);
        $statement = $db->prepare("UPDATE {$table} SET {$column} = :value WHERE id = :id");
        foreach ($rows as $row) {
            $plaintext = Crypto::decryptWithKey((string) $row[$column], $oldKey);
            $statement->execute([
                'value' => Crypto::encryptWithKey($plaintext, $newKey),
                'id' => (int) $row['id'],
            ]);
            $updated++;
        }
    }
    $db->commit();
    fwrite(STDOUT, "Re-encrypted {$updated} secret(s). Update APP_KEY to FPDP_NEW_APP_KEY and restart the application.\n");
} catch (\Throwable $exception) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    fwrite(STDERR, "Rotation aborted; no values were changed: {$exception->getMessage()}\n");
    exit(1);
}
