<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use App\Core\Config;
use App\Core\Database;
use App\Core\Exceptions\ValidationException;
use App\Core\Uuid;
use App\Repositories\FederatedConnectionRepository;
use App\Repositories\FederatedPostRepository;
use App\Repositories\FollowRepository;
use App\Repositories\ProfileRepository;
use App\Repositories\RemoteActorRepository;
use App\Repositories\RemoteNodeRepository;
use App\Services\Federation\FederationService;

function fresend_assert(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

$dbPath = sys_get_temp_dir() . '/fpdp-followresend-test-' . uniqid() . '.sqlite';
$envPath = sys_get_temp_dir() . '/fpdp-followresend-test-' . uniqid() . '.env';
file_put_contents($envPath, "APP_ENV=testing\nDB_CONNECTION=sqlite\nDB_DATABASE={$dbPath}\nNODE_DOMAIN=test.local\n");
Config::load($envPath);
Database::reset();
$db = Database::connection();

foreach ([
    'CREATE TABLE nodes (id INTEGER PRIMARY KEY AUTOINCREMENT, public_id TEXT UNIQUE, domain TEXT UNIQUE, name TEXT, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP)',
    'CREATE TABLE users (id INTEGER PRIMARY KEY AUTOINCREMENT, public_id TEXT UNIQUE, node_id INTEGER, email TEXT UNIQUE, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP)',
    'CREATE TABLE profiles (id INTEGER PRIMARY KEY AUTOINCREMENT, public_id TEXT UNIQUE, user_id INTEGER UNIQUE, handle TEXT UNIQUE, display_name TEXT, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP)',
    'CREATE TABLE remote_nodes (id INTEGER PRIMARY KEY AUTOINCREMENT, public_id TEXT UNIQUE, domain TEXT UNIQUE, trust_state TEXT DEFAULT "UNKNOWN", created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP)',
    'CREATE TABLE remote_actors (id INTEGER PRIMARY KEY AUTOINCREMENT, public_id TEXT UNIQUE, remote_node_id INTEGER, actor_uri TEXT, federated_address TEXT UNIQUE, display_name TEXT, fetched_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP)',
    'CREATE TABLE federated_connections (id INTEGER PRIMARY KEY AUTOINCREMENT, public_id TEXT UNIQUE, profile_id INTEGER, remote_actor_id INTEGER, relationship_status TEXT DEFAULT "PENDING", show_on_profile INTEGER DEFAULT 1, accepted_at TIMESTAMP, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP)',
    'CREATE TABLE federated_posts (id INTEGER PRIMARY KEY AUTOINCREMENT, public_id TEXT UNIQUE, remote_actor_id INTEGER, object_uri TEXT, published_at TIMESTAMP, deleted_at TIMESTAMP)',
    'CREATE TABLE follows (id INTEGER PRIMARY KEY AUTOINCREMENT, public_id TEXT UNIQUE, profile_id INTEGER, remote_actor_id INTEGER, target_actor_uri TEXT, target_federated_address TEXT, status TEXT DEFAULT "PENDING", direction TEXT DEFAULT "OUTGOING", activity_public_id TEXT, accepted_at TIMESTAMP, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP)',
    'CREATE TABLE federation_activities (id INTEGER PRIMARY KEY AUTOINCREMENT, public_id TEXT UNIQUE, node_id INTEGER, direction TEXT, activity_type TEXT, actor_uri TEXT, object_uri TEXT, target_node_domain TEXT, target_actor_uri TEXT, payload TEXT, signature TEXT, status TEXT DEFAULT "PENDING", retry_count INTEGER DEFAULT 0, last_error TEXT, next_attempt_at TIMESTAMP, delivered_at TIMESTAMP, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP)',
] as $sql) {
    $db->exec($sql);
}

$db->exec('INSERT INTO nodes (public_id, domain, name) VALUES (\'' . Uuid::v4() . '\', "test.local", "Test")');
$nodeId = (int) $db->lastInsertId();
$db->exec('INSERT INTO users (public_id, node_id, email) VALUES (\'' . Uuid::v4() . '\', ' . $nodeId . ', "owner@test.local")');
$userId = (int) $db->lastInsertId();
$profileStmt = $db->prepare('INSERT INTO profiles (public_id, user_id, handle, display_name) VALUES (:pid, :uid, "owner", "Owner")');
$profileStmt->execute(['pid' => Uuid::v4(), 'uid' => $userId]);
$profileId = (int) $db->lastInsertId();

$federation = new FederationService(
    new FederatedConnectionRepository($db),
    new RemoteActorRepository($db),
    new RemoteNodeRepository($db),
    new FederatedPostRepository($db),
    new ProfileRepository($db),
);

$targetActorUri = 'https://mastodon.social/users/kukuhtw';

// ---- Test 1: first follow succeeds, status PENDING ----
$first = $federation->sendFollow($nodeId, $profileId, $targetActorUri, 'mastodon.social');
fresend_assert($first['status'] === 'PENDING', 'First follow should be PENDING: ' . json_encode($first));
$firstActivityId = $first['activity_id'];

// ---- Test 2: resending while still PENDING (the real-world stuck case) succeeds instead of throwing, with a fresh activity id ----
$second = $federation->sendFollow($nodeId, $profileId, $targetActorUri, 'mastodon.social');
fresend_assert($second['status'] === 'PENDING', 'Resending a still-PENDING follow should succeed, not throw: ' . json_encode($second));
fresend_assert($second['follow_id'] === $first['follow_id'], 'Resending should reuse the same follow row, not create a duplicate');
fresend_assert($second['activity_id'] !== $firstActivityId, 'Resending should queue a fresh Follow activity id, not replay the stale one');

$rowCount = (int) $db->query('SELECT COUNT(*) FROM follows')->fetchColumn();
fresend_assert($rowCount === 1, "Resending must not create a second follows row, found {$rowCount}");

// ---- Test 3: once ACCEPTED, sending again is correctly blocked ----
$followRepo = new FollowRepository($db);
$followRepo->updateStatus($first['follow_id'], 'ACCEPTED', null);
$threw = false;
try {
    $federation->sendFollow($nodeId, $profileId, $targetActorUri, 'mastodon.social');
} catch (ValidationException $e) {
    $threw = true;
}
fresend_assert($threw, 'Sending a follow that is already ACCEPTED should still be blocked');

// ---- Test 4: after an explicit Undo (DISCONNECTED), following again is allowed ----
$federation->sendUndo($nodeId, $profileId, $first['follow_id']);
$afterUndo = $db->query('SELECT status FROM follows WHERE public_id = ' . $db->quote($first['follow_id']))->fetch();
fresend_assert($afterUndo['status'] === 'DISCONNECTED', 'Undo should set status to DISCONNECTED: ' . json_encode($afterUndo));

$fourth = $federation->sendFollow($nodeId, $profileId, $targetActorUri, 'mastodon.social');
fresend_assert($fourth['status'] === 'PENDING', 'Re-following after an explicit unfollow should succeed: ' . json_encode($fourth));

Database::reset();
unset($db);
@unlink($envPath);
@unlink($dbPath);
fwrite(STDOUT, "Follow resend test passed\n");
