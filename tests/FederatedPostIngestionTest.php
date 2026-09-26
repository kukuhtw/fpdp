<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use App\Core\Config;
use App\Core\Database;
use App\Core\Http\Request;
use App\Core\Router;
use App\Core\Uuid;
use App\Services\Federation\HttpSignature;

function fpost_assert(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

$dbPath = sys_get_temp_dir() . '/fpdp-fedpost-test-' . uniqid() . '.sqlite';
$envPath = sys_get_temp_dir() . '/fpdp-fedpost-test-' . uniqid() . '.env';
file_put_contents($envPath, "APP_ENV=testing\nDB_CONNECTION=sqlite\nDB_DATABASE={$dbPath}\nNODE_DOMAIN=test.local\nAUTH_TOKEN_TTL=3600\n");
Config::load($envPath);
Database::reset();
$db = Database::connection();

foreach ([
    'CREATE TABLE nodes (id INTEGER PRIMARY KEY AUTOINCREMENT, public_id TEXT UNIQUE, domain TEXT UNIQUE, name TEXT, default_locale TEXT, timezone TEXT, status TEXT DEFAULT "ACTIVE", capabilities TEXT, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP)',
    'CREATE TABLE users (id INTEGER PRIMARY KEY AUTOINCREMENT, public_id TEXT UNIQUE, node_id INTEGER, email TEXT UNIQUE, password_hash TEXT, role TEXT DEFAULT "OWNER", status TEXT DEFAULT "ACTIVE", created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP)',
    'CREATE TABLE profiles (id INTEGER PRIMARY KEY AUTOINCREMENT, public_id TEXT UNIQUE, user_id INTEGER UNIQUE, handle TEXT UNIQUE, display_name TEXT, bio TEXT, avatar_url TEXT, visibility TEXT DEFAULT "PUBLIC", links TEXT, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP)',
    'CREATE TABLE auth_tokens (id INTEGER PRIMARY KEY AUTOINCREMENT, user_id INTEGER, token_hash TEXT UNIQUE, token_type TEXT DEFAULT "ACCESS", scopes TEXT, expires_at TIMESTAMP, revoked_at TIMESTAMP, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP)',
    'CREATE TABLE rate_limits (id INTEGER PRIMARY KEY AUTOINCREMENT, rate_key TEXT UNIQUE, attempts INTEGER DEFAULT 1, window_started_at TIMESTAMP, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP)',
    'CREATE TABLE remote_nodes (id INTEGER PRIMARY KEY AUTOINCREMENT, public_id TEXT UNIQUE, domain TEXT UNIQUE, name TEXT, status TEXT DEFAULT "ACTIVE", trust_state TEXT DEFAULT "UNKNOWN", last_seen_at TIMESTAMP, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP)',
    'CREATE TABLE remote_actors (id INTEGER PRIMARY KEY AUTOINCREMENT, public_id TEXT UNIQUE, remote_node_id INTEGER, actor_uri TEXT, federated_address TEXT UNIQUE, display_name TEXT, avatar_url TEXT, canonical_url TEXT, inbox_url TEXT, shared_inbox_url TEXT, public_key_id TEXT, public_key_pem TEXT, fetched_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP)',
    'CREATE TABLE federated_connections (id INTEGER PRIMARY KEY AUTOINCREMENT, public_id TEXT UNIQUE, profile_id INTEGER, remote_actor_id INTEGER, relationship_status TEXT DEFAULT "PENDING", show_on_profile INTEGER DEFAULT 1, accepted_at TIMESTAMP, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, UNIQUE (profile_id, remote_actor_id))',
    'CREATE TABLE federated_posts (id INTEGER PRIMARY KEY AUTOINCREMENT, public_id TEXT UNIQUE, remote_actor_id INTEGER, object_uri TEXT, canonical_url TEXT, title TEXT, content TEXT, attachments TEXT, visibility TEXT DEFAULT "PUBLIC", published_at TIMESTAMP, fetched_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, deleted_at TIMESTAMP)',
    'CREATE TABLE node_keys (id INTEGER PRIMARY KEY AUTOINCREMENT, node_id INTEGER, key_type TEXT DEFAULT "rsa", public_key TEXT, private_key TEXT, fingerprint TEXT UNIQUE, is_current INTEGER DEFAULT 1, rotated_at TIMESTAMP, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP)',
    'CREATE TABLE follows (id INTEGER PRIMARY KEY AUTOINCREMENT, public_id TEXT UNIQUE, profile_id INTEGER, remote_actor_id INTEGER, target_actor_uri TEXT, target_federated_address TEXT, status TEXT DEFAULT "PENDING", direction TEXT DEFAULT "OUTGOING", activity_public_id TEXT, accepted_at TIMESTAMP, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP)',
    'CREATE TABLE federation_activities (id INTEGER PRIMARY KEY AUTOINCREMENT, public_id TEXT UNIQUE, node_id INTEGER, direction TEXT, activity_type TEXT, actor_uri TEXT, object_uri TEXT, target_node_domain TEXT, target_actor_uri TEXT, payload TEXT, signature TEXT, status TEXT DEFAULT "PENDING", retry_count INTEGER DEFAULT 0, last_error TEXT, next_attempt_at TIMESTAMP, delivered_at TIMESTAMP, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP)',
] as $sql) {
    $db->exec($sql);
}

/** @var Router $router */
$router = require __DIR__ . '/../app/routes.php';

/**
 * @param array<string, string> $headers
 * @param array<string, string> $query
 * @return array{status: int, body: mixed, contentType: ?string}
 */
$dispatch = static function (string $method, string $path, ?string $rawBody = null, array $headers = [], array $query = []) use ($router): array {
    $response = $router->dispatch(new Request($method, $path, $query, $rawBody, $headers));

    return [
        'status' => $response->status,
        'body' => $response->body === '' ? null : json_decode($response->body, true),
        'contentType' => $response->headers['Content-Type'] ?? null,
    ];
};

/** @return array{privateKey: string, publicKeyPem: string, actorUri: string, keyId: string, inboxUrl: string, remoteActorId: int} */
function fpost_seed_remote_actor(PDO $db, string $handle, string $domain): array
{
    ['private_key' => $privateKey, 'public_key' => $publicKeyPem] = \App\Services\Federation\NodeKeyService::createRsaKeyPair(2048);

    $actorUri = "https://{$domain}/users/{$handle}";
    $keyId = $actorUri . '#main-key';
    $inboxUrl = $actorUri . '/inbox';

    $nodeStmt = $db->prepare('INSERT INTO remote_nodes (public_id, domain, trust_state) VALUES (:pid, :domain, "UNKNOWN")');
    $nodeStmt->execute(['pid' => Uuid::v4(), 'domain' => $domain]);
    $nodeId = (int) $db->lastInsertId();

    $actorStmt = $db->prepare(
        'INSERT INTO remote_actors (public_id, remote_node_id, actor_uri, federated_address, display_name, inbox_url, public_key_id, public_key_pem, fetched_at)
         VALUES (:pid, :node_id, :actor_uri, :fed_addr, :name, :inbox, :key_id, :pem, CURRENT_TIMESTAMP)',
    );
    $actorStmt->execute([
        'pid' => Uuid::v4(), 'node_id' => $nodeId, 'actor_uri' => $actorUri, 'fed_addr' => "@{$handle}@{$domain}",
        'name' => ucfirst($handle), 'inbox' => $inboxUrl, 'key_id' => $keyId, 'pem' => $publicKeyPem,
    ]);

    return ['privateKey' => $privateKey, 'publicKeyPem' => $publicKeyPem, 'actorUri' => $actorUri, 'keyId' => $keyId, 'inboxUrl' => $inboxUrl, 'remoteActorId' => (int) $db->lastInsertId()];
}

/** @return array{headers: array<string, string>, body: string} */
function fpost_sign_request(string $privateKeyPem, string $keyId, string $method, string $path, string $host, array $payload): array
{
    $body = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    $headers = ['host' => $host, 'date' => HttpSignature::httpDate(), 'digest' => HttpSignature::digestHeader($body)];
    $signingString = HttpSignature::buildSigningString($method, $path, $headers, HttpSignature::DEFAULT_SIGNED_HEADERS);

    $signature = '';
    openssl_sign($signingString, $signature, $privateKeyPem, OPENSSL_ALGO_SHA256);
    $headers['signature'] = HttpSignature::buildSignatureHeader($keyId, HttpSignature::DEFAULT_SIGNED_HEADERS, base64_encode($signature));

    return ['headers' => $headers, 'body' => $body];
}

// ---- Register the local owner and seed a remote actor the owner already follows ----
$register = $dispatch('POST', '/api/v1/auth/register', json_encode([
    'email' => 'owner@test.local', 'password' => 'correct horse battery', 'handle' => 'owner', 'display_name' => 'Owner',
]));
fpost_assert($register['status'] === 201, 'Owner registration failed: ' . json_encode($register));
$inboxPath = '/@owner/inbox';

$profileId = (int) $db->query('SELECT id FROM profiles LIMIT 1')->fetchColumn();
$remote = fpost_seed_remote_actor($db, 'alice', 'sender.example');

$connStmt = $db->prepare(
    'INSERT INTO federated_connections (public_id, profile_id, remote_actor_id, relationship_status, accepted_at)
     VALUES (:pid, :profile_id, :actor_id, "CONNECTED", CURRENT_TIMESTAMP)',
);
$connStmt->execute(['pid' => Uuid::v4(), 'profile_id' => $profileId, 'actor_id' => $remote['remoteActorId']]);

// ---- Test 1: an inbound Create(Note) from a followed actor is stored as a federated post ----
$noteUri = 'https://sender.example/notes/' . Uuid::v4();
$createPayload = [
    '@context' => 'https://www.w3.org/ns/activitystreams',
    'id' => 'https://sender.example/activities/' . Uuid::v4(),
    'type' => 'Create',
    'actor' => $remote['actorUri'],
    'published' => gmdate('c'),
    'object' => [
        'id' => $noteUri,
        'type' => 'Note',
        'attributedTo' => $remote['actorUri'],
        'content' => '<p>Hello from the fediverse!</p>',
        'url' => [['type' => 'Link', 'href' => 'https://sender.example/@alice/' . Uuid::v4()]],
        'published' => gmdate('c'),
        'to' => ['https://www.w3.org/ns/activitystreams#Public'],
        'cc' => [$remote['actorUri'] . '/followers'],
        'attachment' => [
            ['type' => 'Document', 'mediaType' => 'image/jpeg', 'url' => 'https://sender.example/media/photo.jpg', 'name' => 'A photo'],
            // A malicious/malformed attachment url must be silently dropped, not stored.
            ['type' => 'Document', 'mediaType' => 'image/png', 'url' => 'javascript:alert(1)', 'name' => 'evil'],
        ],
    ],
];
$signedCreate = fpost_sign_request($remote['privateKey'], $remote['keyId'], 'POST', $inboxPath, 'test.local', $createPayload);
$createResult = $dispatch('POST', $inboxPath, $signedCreate['body'], $signedCreate['headers']);
fpost_assert($createResult['status'] === 202, 'Signed Create should be accepted: ' . json_encode($createResult));

$storedPost = $db->query('SELECT * FROM federated_posts WHERE object_uri = ' . $db->quote($noteUri))->fetch();
fpost_assert($storedPost !== false, 'Create should insert a federated_posts row');
fpost_assert($storedPost['content'] === '<p>Hello from the fediverse!</p>', 'Stored content should match the object content: ' . json_encode($storedPost));
fpost_assert($storedPost['visibility'] === 'PUBLIC', 'A Note addressed to the Public collection should be stored as PUBLIC: ' . json_encode($storedPost));
fpost_assert(str_starts_with((string) $storedPost['canonical_url'], 'https://sender.example/@alice/'), 'canonical_url should come from the Link url, not the object id: ' . json_encode($storedPost));
fpost_assert((int) $storedPost['remote_actor_id'] === $remote['remoteActorId'], 'Post should be attributed to the sending remote actor');

$attachments = json_decode((string) $storedPost['attachments'], true);
fpost_assert(is_array($attachments) && count($attachments) === 1, 'Exactly one valid attachment should be stored (the javascript: URI one dropped): ' . json_encode($storedPost['attachments']));
fpost_assert($attachments[0]['media_type'] === 'IMAGE', 'image/jpeg should map to media_type IMAGE: ' . json_encode($attachments));
fpost_assert($attachments[0]['url'] === 'https://sender.example/media/photo.jpg', 'Attachment url should be preserved');
fpost_assert($attachments[0]['alt_text'] === 'A photo', 'Attachment name should map to alt_text');

// ---- Test 2: a duplicate Create for the same object (different activity id) is a no-op, not a second row ----
$dupCreatePayload = $createPayload;
$dupCreatePayload['id'] = 'https://sender.example/activities/' . Uuid::v4();
$signedDup = fpost_sign_request($remote['privateKey'], $remote['keyId'], 'POST', $inboxPath, 'test.local', $dupCreatePayload);
$dupResult = $dispatch('POST', $inboxPath, $signedDup['body'], $signedDup['headers']);
fpost_assert($dupResult['status'] === 202, 'Duplicate-object Create should still be acknowledged: ' . json_encode($dupResult));
$postCount = (int) $db->query('SELECT COUNT(*) FROM federated_posts WHERE object_uri = ' . $db->quote($noteUri))->fetchColumn();
fpost_assert($postCount === 1, "Re-sending a Create for the same object must not duplicate the row, found {$postCount}");

// ---- Test 3: an Update changes the stored content ----
$updatePayload = [
    '@context' => 'https://www.w3.org/ns/activitystreams',
    'id' => 'https://sender.example/activities/' . Uuid::v4(),
    'type' => 'Update',
    'actor' => $remote['actorUri'],
    'published' => gmdate('c'),
    'object' => [
        'id' => $noteUri,
        'type' => 'Note',
        'content' => '<p>Hello from the fediverse! (edited)</p>',
        'to' => ['https://www.w3.org/ns/activitystreams#Public'],
    ],
];
$signedUpdate = fpost_sign_request($remote['privateKey'], $remote['keyId'], 'POST', $inboxPath, 'test.local', $updatePayload);
$updateResult = $dispatch('POST', $inboxPath, $signedUpdate['body'], $signedUpdate['headers']);
fpost_assert($updateResult['status'] === 202, 'Signed Update should be accepted: ' . json_encode($updateResult));
$updatedPost = $db->query('SELECT content FROM federated_posts WHERE object_uri = ' . $db->quote($noteUri))->fetch();
fpost_assert($updatedPost['content'] === '<p>Hello from the fediverse! (edited)</p>', 'Update should overwrite the stored content: ' . json_encode($updatedPost));

// ---- Test 4: a Delete (bare tombstone id, as Mastodon sends it) soft-deletes the post ----
$deletePayload = [
    '@context' => 'https://www.w3.org/ns/activitystreams',
    'id' => 'https://sender.example/activities/' . Uuid::v4(),
    'type' => 'Delete',
    'actor' => $remote['actorUri'],
    'published' => gmdate('c'),
    'object' => $noteUri,
];
$signedDelete = fpost_sign_request($remote['privateKey'], $remote['keyId'], 'POST', $inboxPath, 'test.local', $deletePayload);
$deleteResult = $dispatch('POST', $inboxPath, $signedDelete['body'], $signedDelete['headers']);
fpost_assert($deleteResult['status'] === 202, 'Signed Delete should be accepted: ' . json_encode($deleteResult));
$deletedPost = $db->query('SELECT deleted_at FROM federated_posts WHERE object_uri = ' . $db->quote($noteUri))->fetch();
fpost_assert($deletedPost['deleted_at'] !== null, 'Delete should soft-delete the post (set deleted_at): ' . json_encode($deletedPost));

// ---- Test 5: a Create from an actor the owner does NOT follow is ignored, not stored ----
$stranger = fpost_seed_remote_actor($db, 'bob', 'stranger.example');
$strangerNoteUri = 'https://stranger.example/notes/' . Uuid::v4();
$strangerPayload = [
    '@context' => 'https://www.w3.org/ns/activitystreams',
    'id' => 'https://stranger.example/activities/' . Uuid::v4(),
    'type' => 'Create',
    'actor' => $stranger['actorUri'],
    'published' => gmdate('c'),
    'object' => ['id' => $strangerNoteUri, 'type' => 'Note', 'content' => '<p>Uninvited post</p>', 'to' => ['https://www.w3.org/ns/activitystreams#Public']],
];
$signedStranger = fpost_sign_request($stranger['privateKey'], $stranger['keyId'], 'POST', $inboxPath, 'test.local', $strangerPayload);
$strangerResult = $dispatch('POST', $inboxPath, $signedStranger['body'], $signedStranger['headers']);
fpost_assert($strangerResult['status'] === 202, 'Create from an unfollowed actor should still be acknowledged (not an error): ' . json_encode($strangerResult));
$strangerStored = $db->query('SELECT COUNT(*) FROM federated_posts WHERE object_uri = ' . $db->quote($strangerNoteUri))->fetchColumn();
fpost_assert((int) $strangerStored === 0, 'A Create from an actor with no accepted connection to any local profile must not be stored');

Database::reset();
unset($db, $router, $dispatch);
@unlink($envPath);
@unlink($dbPath);
fwrite(STDOUT, "Federated post ingestion test passed\n");
