<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use App\Core\Config;
use App\Core\Database;
use App\Core\Exceptions\ValidationException;
use App\Core\Http\HttpClient;
use App\Repositories\FollowRepository;
use App\Repositories\RemoteActorRepository;
use App\Repositories\RemoteNodeRepository;
use App\Services\Federation\FediverseDiscoveryService;
use App\Services\Federation\NodeDiscoveryService;

function disc_assert(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

/** A fake fediverse: routes URLs to canned JSON and records every request. */
final class FakeFediverseHttpClient extends HttpClient
{
    /** @var array<int, string> */
    public array $requested = [];

    /** @param array<string, array{0: int, 1: mixed}> $routes */
    public function __construct(private readonly array $routes)
    {
    }

    public function get(string $url, array $headers = [], ?int $timeout = null, ?int $maxSize = null): array
    {
        return $this->request('GET', $url, $headers, null, $timeout, $maxSize);
    }

    public function request(string $method, string $url, array $headers = [], ?string $body = null, ?int $timeout = null, ?int $maxSize = null): array
    {
        $this->requested[] = $url;
        [$status, $payload] = $this->routes[$url] ?? [404, ['error' => 'not found']];

        return ['status' => $status, 'headers' => [], 'body' => is_string($payload) ? $payload : json_encode($payload), 'url' => $url];
    }
}

$dbPath = sys_get_temp_dir() . '/fpdp-discovery-test-' . uniqid() . '.sqlite';
$envPath = sys_get_temp_dir() . '/fpdp-discovery-test-' . uniqid() . '.env';
file_put_contents($envPath, "APP_ENV=testing\nDB_CONNECTION=sqlite\nDB_DATABASE={$dbPath}\n");
Config::load($envPath);
Database::reset();
$db = Database::connection();

foreach ([
    'CREATE TABLE remote_nodes (id INTEGER PRIMARY KEY AUTOINCREMENT, public_id TEXT UNIQUE, domain TEXT UNIQUE, name TEXT, status TEXT DEFAULT "ACTIVE", trust_state TEXT DEFAULT "UNKNOWN", last_seen_at TIMESTAMP, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP)',
    'CREATE TABLE remote_actors (id INTEGER PRIMARY KEY AUTOINCREMENT, public_id TEXT UNIQUE, remote_node_id INTEGER, actor_uri TEXT UNIQUE, federated_address TEXT UNIQUE, display_name TEXT, avatar_url TEXT, canonical_url TEXT, inbox_url TEXT, shared_inbox_url TEXT, public_key_id TEXT, public_key_pem TEXT, fetched_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP)',
    'CREATE TABLE follows (id INTEGER PRIMARY KEY AUTOINCREMENT, public_id TEXT UNIQUE, profile_id INTEGER, remote_actor_id INTEGER, target_actor_uri TEXT, target_federated_address TEXT, status TEXT DEFAULT "PENDING", activity_public_id TEXT, accepted_at TIMESTAMP, direction TEXT DEFAULT "OUTGOING", created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP)',
    'CREATE TABLE federated_posts (id INTEGER PRIMARY KEY AUTOINCREMENT, public_id TEXT UNIQUE, remote_actor_id INTEGER, object_uri TEXT UNIQUE, canonical_url TEXT, title TEXT, content TEXT, attachments TEXT, visibility TEXT DEFAULT "PUBLIC", published_at TIMESTAMP, fetched_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP, deleted_at TIMESTAMP)',
] as $sql) {
    $db->exec($sql);
}

$alice = 'https://social.example/users/alice';
$http = new FakeFediverseHttpClient([
    // ---- alice: a full ActivityPub profile ----
    'https://social.example/.well-known/webfinger?resource=acct%3Aalice%40social.example' => [200, ['links' => [
        ['rel' => 'self', 'type' => 'application/activity+json', 'href' => $alice],
    ]]],
    $alice => [200, [
        'id' => $alice, 'type' => 'Person', 'preferredUsername' => 'alice', 'name' => 'Alice',
        'summary' => '<p>Hello <b>world</b></p><script>alert(1)</script><p>Line two &amp; more</p>',
        'icon' => ['url' => 'https://social.example/avatars/alice.png'],
        'url' => 'https://social.example/@alice',
        'inbox' => "{$alice}/inbox", 'manuallyApprovesFollowers' => true,
        'followers' => "{$alice}/followers", 'following' => "{$alice}/following", 'outbox' => "{$alice}/outbox",
        'publicKey' => ['id' => "{$alice}#main-key", 'publicKeyPem' => 'PEM'],
    ]],
    "{$alice}/followers" => [200, ['type' => 'OrderedCollection', 'totalItems' => 120]],
    "{$alice}/following" => [403, ['error' => 'hidden']],
    "{$alice}/outbox" => [200, ['type' => 'OrderedCollection', 'totalItems' => 42, 'first' => "{$alice}/outbox?page=true"]],
    "{$alice}/outbox?page=true" => [200, ['orderedItems' => [
        ['type' => 'Announce', 'object' => 'https://elsewhere.example/notes/1'],
        ['type' => 'Create', 'object' => ['type' => 'Note', 'content' => '<p>First <a href="https://x.example">post</a></p>', 'published' => '2026-09-20T10:00:00Z', 'url' => 'https://social.example/@alice/1']],
        ['type' => 'Create', 'object' => 'https://social.example/notes/only-a-uri'],
        ['type' => 'Create', 'object' => ['type' => 'Note', 'content' => 'Second', 'published' => '2026-09-19T10:00:00Z', 'url' => 'javascript:alert(1)']],
    ]]],
    // ---- bob: outbox paging points at another host, which must not be fetched ----
    'https://social.example/users/bob' => [200, [
        'id' => 'https://social.example/users/bob', 'type' => 'Service', 'preferredUsername' => 'bob', 'name' => 'Bob Bot',
        'inbox' => 'https://social.example/users/bob/inbox',
        'outbox' => 'https://social.example/users/bob/outbox',
        'icon' => ['url' => 'javascript:alert(1)'],
    ]],
    'https://social.example/users/bob/outbox' => [200, ['totalItems' => 3, 'first' => 'https://evil.example/page']],
    // ---- Mastodon REST APIs ----
    'https://mastodon.example/api/v1/directory?local=true&order=active&limit=20&offset=0' => [200, [
        ['acct' => 'carol', 'display_name' => 'Carol <i>C</i>', 'note' => '<p>Artist</p>', 'avatar' => 'https://mastodon.example/a/carol.png', 'url' => 'https://mastodon.example/@carol', 'followers_count' => 10, 'statuses_count' => 5],
        ['acct' => 'bad acct with spaces'],
        ['acct' => 'dan', 'avatar' => 'data:image/png;base64,xx', 'bot' => true],
    ]],
    'https://mastodon.example/api/v1/timelines/tag/php?limit=20' => [200, [
        ['content' => '<p>Newest #php</p>', 'created_at' => '2026-09-25T10:00:00Z', 'url' => 'https://mastodon.example/@carol/9', 'account' => ['acct' => 'carol', 'display_name' => 'Carol']],
        ['content' => '<p>Older #php</p>', 'account' => ['acct' => 'carol', 'display_name' => 'Carol']],
        ['content' => '<p>From afar</p>', 'account' => ['acct' => 'dave@other.example', 'display_name' => 'Dave']],
    ]],
    'https://pleroma.example/api/v1/directory?local=true&order=active&limit=20&offset=0' => [404, ['error' => 'Not found']],
    'https://private.example/api/v1/timelines/tag/php?limit=20' => [422, ['error' => 'This method requires an authenticated user']],
]);

$actors = new RemoteActorRepository($db);
$follows = new FollowRepository($db);
$service = new FediverseDiscoveryService(
    new NodeDiscoveryService(new RemoteNodeRepository($db), $actors, $http),
    $follows,
    $actors,
    $http,
);
$profileId = 1;
$nodeId = 1;

$throwsValidation = static function (callable $fn): ?string {
    try {
        $fn();
    } catch (ValidationException $e) {
        return $e->getMessage();
    }

    return null;
};

// ---- 1. Lookup: preview with counts, recent posts, relationship; remote HTML made safe ----
$db->exec("INSERT INTO follows (public_id, profile_id, target_actor_uri, status, direction) VALUES ('f-out', 1, '{$alice}', 'PENDING', 'OUTGOING'), ('f-in', 1, '{$alice}', 'ACCEPTED', 'INCOMING')");
$preview = $service->lookup($nodeId, $profileId, '@alice@social.example');

disc_assert($preview['actor_uri'] === $alice && $preview['address'] === '@alice@social.example', 'lookup should resolve the account via WebFinger');
disc_assert($preview['display_name'] === 'Alice' && $preview['locked'] === true, 'profile name and "requires approval" should be reported');
disc_assert($preview['summary'] === "Hello world\nLine two & more", 'the bio must come back as plain text, script content dropped: ' . json_encode($preview['summary']));
disc_assert(!str_contains($preview['summary'], '<script') && !str_contains($preview['summary'], '<b>'), 'no HTML may survive in the bio');
disc_assert($preview['avatar_url'] === 'https://social.example/avatars/alice.png' && $preview['profile_url'] === 'https://social.example/@alice', 'avatar and profile URLs should pass through');
disc_assert($preview['followers_count'] === 120 && $preview['posts_count'] === 42, 'follower and post counts should come from the collections');
disc_assert($preview['following_count'] === null, 'a hidden collection should be reported as unknown, not zero');
disc_assert(count($preview['recent_posts']) === 2, 'boosts and URI-only objects are skipped, leaving two posts: ' . json_encode($preview['recent_posts']));
disc_assert($preview['recent_posts'][0]['content'] === 'First post' && $preview['recent_posts'][0]['url'] === 'https://social.example/@alice/1', 'post text is plain and its URL kept');
disc_assert($preview['recent_posts'][1]['url'] === null, 'a javascript: URL must be dropped');
disc_assert($preview['relationship'] === ['following' => 'PENDING', 'followed_by' => true], 'relationship should reflect local follows: ' . json_encode($preview['relationship']));
disc_assert($service->lookup($nodeId, $profileId, 'https://social.example/@alice@social.example')['actor_uri'] === $alice, 'a Mastodon "viewing" URL should resolve too');

// ---- 2. Lookup: another host in the outbox paging is never fetched; unsafe avatar dropped ----
$http->requested = [];
$bob = $service->lookup($nodeId, $profileId, 'https://social.example/users/bob');
disc_assert(!in_array('https://evil.example/page', $http->requested, true), 'a collection page on another host must not be requested');
disc_assert($bob['recent_posts'] === [] && $bob['posts_count'] === 3 && $bob['type'] === 'Service', 'bob should still preview, as a bot, with no posts');
disc_assert($bob['avatar_url'] === null, 'a javascript: avatar must be dropped');
disc_assert($throwsValidation(fn () => $service->lookup($nodeId, $profileId, '@nobody@social.example')) !== null, 'an unknown account should be a validation error');
disc_assert($throwsValidation(fn () => $service->lookup($nodeId, $profileId, '  ')) !== null, 'an empty query should be a validation error');

// ---- 3. Suggestions: follow-back and previously seen, minus followed/blocked ----
$db->exec("INSERT INTO remote_nodes (public_id, domain, trust_state) VALUES ('n-blocked', 'blocked.example', 'BLOCKED'), ('n-ok', 'ok.example', 'UNKNOWN')");
$okNode = (int) $db->query("SELECT id FROM remote_nodes WHERE domain = 'ok.example'")->fetchColumn();
$blockedNode = (int) $db->query("SELECT id FROM remote_nodes WHERE domain = 'blocked.example'")->fetchColumn();
foreach ([['erin', $okNode], ['frank', $okNode], ['gina', $okNode], ['mallory', $blockedNode], ['hank', $okNode]] as [$name, $node]) {
    $db->exec("INSERT INTO remote_actors (public_id, remote_node_id, actor_uri, federated_address, display_name) VALUES ('a-{$name}', {$node}, 'https://x.example/users/{$name}', '@{$name}@x.example', '" . ucfirst($name) . "')");
}
$actorId = static fn (string $name): int => (int) $db->query("SELECT id FROM remote_actors WHERE public_id = 'a-{$name}'")->fetchColumn();
$db->exec("INSERT INTO follows (public_id, profile_id, remote_actor_id, target_actor_uri, status, direction) VALUES
    ('in-erin', 1, {$actorId('erin')}, 'https://x.example/users/erin', 'ACCEPTED', 'INCOMING'),
    ('in-frank', 1, {$actorId('frank')}, 'https://x.example/users/frank', 'ACCEPTED', 'INCOMING'),
    ('out-frank', 1, NULL, 'https://x.example/users/frank', 'ACCEPTED', 'OUTGOING'),
    ('in-mallory', 1, {$actorId('mallory')}, 'https://x.example/users/mallory', 'ACCEPTED', 'INCOMING'),
    ('out-hank', 1, NULL, 'https://x.example/users/hank', 'BLOCKED', 'OUTGOING')");
foreach ([['gina', 1], ['gina', 2], ['hank', 3], ['frank', 4]] as [$name, $n]) {
    $db->exec("INSERT INTO federated_posts (public_id, remote_actor_id, object_uri, content, published_at) VALUES ('p{$n}', {$actorId($name)}, 'https://x.example/notes/{$n}', 'hi', '2026-09-2{$n} 00:00:00')");
}

$http->requested = [];
$suggestions = $service->suggestions($profileId);
disc_assert($http->requested === [], 'suggestions must not contact any server');
disc_assert(array_column($suggestions['follow_back'], 'address') === ['@erin@x.example'], 'follow-back should list only erin (frank is followed, mallory is on a blocked node): ' . json_encode($suggestions['follow_back']));
disc_assert(array_column($suggestions['seen_before'], 'address') === ['@gina@x.example'], 'seen-before should list only gina (frank followed, hank blocked): ' . json_encode($suggestions['seen_before']));
disc_assert($suggestions['seen_before'][0]['post_count'] === 2, 'seen-before should report how many posts reached the node');

// ---- 4. Server directory ----
$directory = $service->directory($nodeId, 'https://Mastodon.Example/', 0);
disc_assert($directory['domain'] === 'mastodon.example', 'the domain should be normalized');
disc_assert(array_column($directory['accounts'], 'address') === ['@carol@mastodon.example', '@dan@mastodon.example'], 'local accts become full addresses and malformed ones are skipped: ' . json_encode($directory['accounts']));
disc_assert($directory['accounts'][0]['display_name'] === 'Carol C' && $directory['accounts'][0]['summary'] === 'Artist', 'names and notes are plain text');
disc_assert($directory['accounts'][1]['avatar_url'] === null && $directory['accounts'][1]['bot'] === true, 'a data: avatar is dropped and bots are flagged');
disc_assert($directory['next_offset'] === null, 'a short page means there is no next page');
disc_assert(str_contains((string) $throwsValidation(fn () => $service->directory($nodeId, 'pleroma.example')), 'directory'), 'a server without the directory API should get a clear message');

// ---- 5. Hashtag ----
$hashtag = $service->hashtag($nodeId, 'mastodon.example', '#php');
disc_assert($hashtag['tag'] === 'php', 'the leading # is stripped');
disc_assert(array_column($hashtag['accounts'], 'address') === ['@carol@mastodon.example', '@dave@other.example'], 'authors are deduplicated and remote accts keep their own domain: ' . json_encode($hashtag['accounts']));
disc_assert($hashtag['accounts'][0]['sample_post']['content'] === 'Newest #php', "each author carries their newest matching post");
disc_assert($throwsValidation(fn () => $service->hashtag($nodeId, 'private.example', 'php')) !== null, 'a server that hides hashtag timelines should get a clear message');

// ---- 6. Input validation keeps requests to real public domains ----
foreach (['localhost', 'http://', 'a b.example', '127.0.0.1', 'example'] as $badDomain) {
    disc_assert($throwsValidation(fn () => $service->directory($nodeId, $badDomain)) !== null, "domain \"{$badDomain}\" should be rejected");
}
disc_assert($throwsValidation(fn () => $service->hashtag($nodeId, 'mastodon.example', 'no spaces')) !== null, 'a hashtag with spaces should be rejected');
disc_assert($throwsValidation(fn () => $service->hashtag($nodeId, 'mastodon.example', '../../api')) !== null, 'a hashtag cannot smuggle a path');

unset($service, $follows, $actors, $http, $db);
Database::reset();
gc_collect_cycles();
unlink($envPath);
@unlink($dbPath);

fwrite(STDOUT, "Fediverse discovery test passed\n");
