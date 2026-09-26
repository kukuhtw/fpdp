<?php

declare(strict_types=1);

namespace App\Core\Http;

use RuntimeException;

/**
 * SSRF-safe HTTP client with URL validation, timeouts, size limits,
 * and controlled redirects. Replaces raw file_get_contents/simplexml_load_file calls.
 *
 * Redirects are followed here, one hop at a time, rather than by PHP's
 * stream wrapper: every Location is validated like the original URL, so a
 * public server cannot bounce a request to a private or metadata address.
 * This matters because remote servers control many of the URLs FPDP fetches
 * (actor documents, feeds, collections), some of them without any login.
 */
class HttpClient
{
    private const DEFAULT_TIMEOUT = 15;
    private const DEFAULT_MAX_SIZE = 5 * 1024 * 1024; // 5 MB
    private const MAX_REDIRECTS = 5;
    private const REDIRECT_STATUSES = [301, 302, 303, 307, 308];
    private const BLOCKED_HOSTS = ['localhost', 'metadata.google.internal'];

    /**
     * @param array<string, string> $headers
     * @return array{status: int, headers: array<string, string>, body: string, url: string}
     */
    public function get(string $url, array $headers = [], ?int $timeout = null, ?int $maxSize = null): array
    {
        return $this->request('GET', $url, $headers, null, $timeout, $maxSize);
    }

    /**
     * @param array<string, string> $headers
     * @return array{status: int, headers: array<string, string>, body: string, url: string}
     */
    public function request(string $method, string $url, array $headers = [], ?string $body = null, ?int $timeout = null, ?int $maxSize = null): array
    {
        $method = strtoupper($method);
        for ($hop = 0; ; $hop++) {
            $this->validateUrl($url);
            $response = $this->send($method, $url, $headers, $body, $timeout ?? self::DEFAULT_TIMEOUT, $maxSize ?? self::DEFAULT_MAX_SIZE);

            $location = $response['headers']['location'] ?? null;
            if (!in_array($response['status'], self::REDIRECT_STATUSES, true) || !is_string($location) || $location === '') {
                return $response + ['url' => $url];
            }
            if ($hop >= self::MAX_REDIRECTS) {
                throw new RuntimeException('Too many redirects for ' . $url);
            }

            $next = self::resolveLocation($url, $location);
            if (strcasecmp((string) parse_url($next, PHP_URL_HOST), (string) parse_url($url, PHP_URL_HOST)) !== 0) {
                // A caller-set Host (and any signature over it) belongs to the
                // original host only.
                $headers = array_filter($headers, static fn (string $name): bool => strcasecmp($name, 'Host') !== 0 && strcasecmp($name, 'Signature') !== 0, ARRAY_FILTER_USE_KEY);
            }
            if ($response['status'] === 303 || (in_array($response['status'], [301, 302], true) && $method !== 'GET' && $method !== 'HEAD')) {
                $method = 'GET';
                $body = null;
            }
            $url = $next;
        }
    }

    /**
     * One HTTP exchange, redirects NOT followed. Protected so tests can fake
     * the network while keeping request()'s validation and redirect logic.
     *
     * @param array<string, string> $headers
     * @return array{status: int, headers: array<string, string>, body: string}
     */
    protected function send(string $method, string $url, array $headers, ?string $body, int $timeout, int $maxSize): array
    {
        $ctx = stream_context_create([
            'http' => [
                'method' => $method,
                'header' => $this->buildHeaders($headers),
                'content' => $body,
                'timeout' => $timeout,
                'follow_location' => 0,
                'ignore_errors' => true,
            ],
            'ssl' => [
                'verify_peer' => true,
                'verify_peer_name' => true,
            ],
        ]);

        $responseBody = @file_get_contents($url, false, $ctx, 0, $maxSize + 1);

        if ($responseBody === false) {
            $error = error_get_last();
            throw new RuntimeException('HTTP request failed: ' . ($error['message'] ?? 'Unknown error'));
        }

        if (strlen($responseBody) > $maxSize) {
            throw new RuntimeException(sprintf('Response size exceeds limit (%d bytes)', $maxSize));
        }

        $responseHeaders = $this->parseResponseHeaders($http_response_header ?? []);

        return [
            'status' => (int) ($responseHeaders[':status'] ?? 200),
            'headers' => $responseHeaders,
            'body' => $responseBody,
        ];
    }

    private static function resolveLocation(string $base, string $location): string
    {
        if (preg_match('#^https?://#i', $location) === 1) {
            return $location;
        }
        $parts = parse_url($base);
        $origin = $parts['scheme'] . '://' . $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : '');
        if (str_starts_with($location, '//')) {
            return $parts['scheme'] . ':' . $location;
        }
        if (str_starts_with($location, '/')) {
            return $origin . $location;
        }
        $path = $parts['path'] ?? '/';

        return $origin . substr($path, 0, (int) strrpos($path, '/') + 1) . $location;
    }

    private function validateUrl(string $url): void
    {
        $parts = parse_url($url);
        if ($parts === false || !isset($parts['scheme'], $parts['host'])) {
            throw new RuntimeException('Invalid URL: ' . $url);
        }

        $scheme = strtolower($parts['scheme']);
        if (!in_array($scheme, ['http', 'https'], true)) {
            throw new RuntimeException('Blocked URL scheme: ' . $scheme);
        }

        $host = trim(strtolower($parts['host']), '[]');

        foreach (self::BLOCKED_HOSTS as $blocked) {
            if ($host === $blocked || str_ends_with($host, '.' . $blocked)) {
                throw new RuntimeException('Blocked host: ' . $host);
            }
        }

        // An IP literal is checked as-is; a name is checked against every
        // address it resolves to, IPv4 and IPv6. A name that resolves to
        // nothing is left to fail at connect time.
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            if (!self::isPublicAddress($host)) {
                throw new RuntimeException('Blocked non-public address: ' . $host);
            }

            return;
        }

        $ipv4 = gethostbynamel($host) ?: [];
        foreach ($ipv4 as $address) {
            if (!self::isPublicAddress($address)) {
                throw new RuntimeException('Blocked non-public address for host: ' . $host . ' (' . $address . ')');
            }
        }
        $records = @dns_get_record($host, DNS_AAAA);
        foreach (is_array($records) ? $records : [] as $record) {
            $address = (string) ($record['ipv6'] ?? '');
            if ($address !== '' && !self::isPublicAddress($address) && !self::isDns64Of($address, $ipv4)) {
                throw new RuntimeException('Blocked non-public address for host: ' . $host . ' (' . $address . ')');
            }
        }
    }

    /**
     * DNS64/NAT64 networks answer AAAA queries with synthesized addresses
     * under a local prefix (often unique-local fd00::/8) that embed the
     * host's IPv4 address in the last 32 bits. Such an address is fine when
     * the embedded IPv4 is one of the host's own, already-validated public
     * A records; any other non-public IPv6 answer stays blocked.
     *
     * @param array<int, string> $publicIpv4
     */
    private static function isDns64Of(string $ipv6, array $publicIpv4): bool
    {
        $packed = @inet_pton($ipv6);
        if ($packed === false || strlen($packed) !== 16) {
            return false;
        }
        $embedded = inet_ntop(substr($packed, 12));

        return $embedded !== false && in_array($embedded, $publicIpv4, true);
    }

    /**
     * Rejects loopback, private, link-local (incl. cloud metadata
     * 169.254.169.254), unspecified, reserved, and carrier-grade NAT
     * ranges, for IPv4 and IPv6, including IPv4-mapped IPv6 addresses.
     */
    private static function isPublicAddress(string $address): bool
    {
        if (preg_match('/^::ffff:(\d+\.\d+\.\d+\.\d+)$/i', $address, $mapped) === 1) {
            $address = $mapped[1];
        }
        if (filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
            return false;
        }
        if (filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
            $long = ip2long($address);
            // 100.64.0.0/10 (carrier-grade NAT) is not covered by the flags above.
            return !($long >= ip2long('100.64.0.0') && $long <= ip2long('100.127.255.255'));
        }

        // IPv6 unique-local fc00::/7 and link-local fe80::/10, in case the
        // flags above miss them on this PHP build.
        $first = strtolower(substr($address, 0, 4));

        return !preg_match('/^f[cd][0-9a-f]{2}$/', $first) && !preg_match('/^fe[89ab][0-9a-f]$/', $first);
    }

    /**
     * @param array<string, string> $headers
     */
    private function buildHeaders(array $headers): string
    {
        $lines = [];
        $hasUserAgent = false;
        foreach ($headers as $name => $value) {
            $lines[] = $name . ': ' . $value;
            if (strcasecmp((string) $name, 'User-Agent') === 0) {
                $hasUserAgent = true;
            }
        }
        if (!$hasUserAgent) {
            $lines[] = 'User-Agent: FPDP-HttpClient/1.0';
        }
        return implode("\r\n", $lines) . "\r\n";
    }

    /**
     * @param array<int, string> $rawHeaders
     * @return array<string, string>
     */
    private function parseResponseHeaders(array $rawHeaders): array
    {
        $parsed = [];
        foreach ($rawHeaders as $line) {
            if (preg_match('/^HTTP\/\d+(?:\.\d+)?\s+(\d+)/i', $line, $m)) {
                $parsed[':status'] = $m[1];
                continue;
            }
            if (str_contains($line, ':')) {
                [$name, $value] = explode(':', $line, 2);
                $parsed[strtolower(trim($name))] = trim($value);
            }
        }
        return $parsed;
    }
}