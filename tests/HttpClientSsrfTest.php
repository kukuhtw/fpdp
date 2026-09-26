<?php

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use App\Core\Http\HttpClient;

function ssrf_assert(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

/**
 * Fakes the network one hop at a time (send() is the single-exchange seam),
 * so request()'s own validation and redirect handling is what's under test.
 * Hosts are IP literals so no DNS lookup happens.
 */
final class ScriptedHttpClient extends HttpClient
{
    /** @var array<int, array{method: string, url: string, headers: array<string, string>}> */
    public array $sent = [];

    /** @param array<string, array{status: int, location?: string, body?: string}> $routes */
    public function __construct(private readonly array $routes)
    {
    }

    protected function send(string $method, string $url, array $headers, ?string $body, int $timeout, int $maxSize): array
    {
        $this->sent[] = ['method' => $method, 'url' => $url, 'headers' => $headers];
        $route = $this->routes[$url] ?? ['status' => 404];
        $responseHeaders = isset($route['location']) ? ['location' => $route['location']] : [];

        return ['status' => $route['status'], 'headers' => $responseHeaders, 'body' => $route['body'] ?? ''];
    }
}

$blocks = static function (HttpClient $client, string $url): bool {
    try {
        $client->get($url);
    } catch (RuntimeException) {
        return true;
    }

    return false;
};

// ---- 1. Non-public addresses are refused before any request is sent ----
$client = new ScriptedHttpClient([]);
foreach ([
    'http://127.0.0.1/', 'http://127.0.0.2/', 'http://10.0.0.5/', 'http://172.16.3.4/', 'http://192.168.1.1/',
    'http://169.254.169.254/latest/meta-data/', 'http://0.0.0.0/', 'http://100.64.0.1/',
    'http://[::1]/', 'http://[fc00::1]/', 'http://[fe80::1]/', 'http://[::ffff:127.0.0.1]/',
    'http://localhost/', 'ftp://93.184.216.34/',
] as $url) {
    ssrf_assert($blocks($client, $url), "{$url} should be blocked");
}
ssrf_assert($client->sent === [], 'no request should reach the network for a blocked URL');

// ---- 2. A public server redirecting to a private address is stopped at the redirect ----
$client = new ScriptedHttpClient([
    'https://93.184.216.34/actor' => ['status' => 302, 'location' => 'http://169.254.169.254/latest/meta-data/'],
]);
ssrf_assert($blocks($client, 'https://93.184.216.34/actor'), 'a redirect to the metadata address must be refused');
ssrf_assert(count($client->sent) === 1, 'the private redirect target must never be requested');

// ---- 3. Legitimate redirects are followed, relative Locations resolved, final URL reported ----
$client = new ScriptedHttpClient([
    'https://93.184.216.34/@alice' => ['status' => 301, 'location' => '/users/alice'],
    'https://93.184.216.34/users/alice' => ['status' => 302, 'location' => 'https://93.184.216.35/users/alice'],
    'https://93.184.216.35/users/alice' => ['status' => 200, 'body' => '{"id":"alice"}'],
]);
$response = $client->get('https://93.184.216.34/@alice', ['Host' => '93.184.216.34', 'Signature' => 'sig', 'Accept' => 'application/json']);
ssrf_assert($response['status'] === 200 && $response['body'] === '{"id":"alice"}', 'the redirect chain should end at the final document');
ssrf_assert($response['url'] === 'https://93.184.216.35/users/alice', 'the reported url should be the final URL, got ' . $response['url']);
ssrf_assert(count($client->sent) === 3, 'three hops should be made');
ssrf_assert(isset($client->sent[1]['headers']['Signature']), 'a same-host redirect keeps the caller headers');
ssrf_assert(!isset($client->sent[2]['headers']['Host']) && !isset($client->sent[2]['headers']['Signature']), 'a cross-host redirect drops Host and Signature');
ssrf_assert(($client->sent[2]['headers']['Accept'] ?? null) === 'application/json', 'other headers survive a cross-host redirect');

// ---- 4. Redirect loops end with an error ----
$client = new ScriptedHttpClient([
    'https://93.184.216.34/a' => ['status' => 302, 'location' => '/b'],
    'https://93.184.216.34/b' => ['status' => 302, 'location' => '/a'],
]);
ssrf_assert($blocks($client, 'https://93.184.216.34/a'), 'a redirect loop should fail');
ssrf_assert(count($client->sent) === 6, 'at most MAX_REDIRECTS (5) redirects are followed, got ' . count($client->sent));

// ---- 5. 303 turns a POST into a GET without a body ----
$client = new ScriptedHttpClient([
    'https://93.184.216.34/submit' => ['status' => 303, 'location' => 'result'],
    'https://93.184.216.34/result' => ['status' => 200, 'body' => 'ok'],
]);
$response = $client->request('POST', 'https://93.184.216.34/submit', [], 'payload');
ssrf_assert($response['body'] === 'ok' && $client->sent[1]['method'] === 'GET', '303 should be followed with GET');

fwrite(STDOUT, "HttpClient SSRF test passed\n");
