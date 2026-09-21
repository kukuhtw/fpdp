<?php

declare(strict_types=1);

namespace App\Core\Http;

use RuntimeException;

/**
 * SSRF-safe HTTP client with URL validation, timeouts, size limits,
 * and controlled redirects. Replaces raw file_get_contents/simplexml_load_file calls.
 */
final class HttpClient
{
    private const DEFAULT_TIMEOUT = 15;
    private const DEFAULT_MAX_SIZE = 5 * 1024 * 1024; // 5 MB
    private const MAX_REDIRECTS = 5;
    private const BLOCKED_HOSTS = ['localhost', '127.0.0.1', '::1', '0.0.0.0', 'metadata.google.internal'];
    private const BLOCKED_PREFIXES = ['10.', '172.16.', '172.17.', '172.18.', '172.19.', '172.20.', '172.21.', '172.22.', '172.23.', '172.24.', '172.25.', '172.26.', '172.27.', '172.28.', '172.29.', '172.30.', '172.31.', '192.168.', '169.254.'];

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
        $this->validateUrl($url);

        $ctx = stream_context_create([
            'http' => [
                'method' => strtoupper($method),
                'header' => $this->buildHeaders($headers),
                'content' => $body,
                'timeout' => $timeout ?? self::DEFAULT_TIMEOUT,
                'follow_location' => 1,
                'max_redirects' => self::MAX_REDIRECTS,
                'ignore_errors' => true,
            ],
            'ssl' => [
                'verify_peer' => true,
                'verify_peer_name' => true,
            ],
        ]);

        $start = microtime(true);
        $responseBody = @file_get_contents($url, false, $ctx);
        $elapsed = microtime(true) - $start;

        if ($responseBody === false) {
            $error = error_get_last();
            throw new RuntimeException('HTTP request failed: ' . ($error['message'] ?? 'Unknown error'));
        }

        $maxResponseSize = $maxSize ?? self::DEFAULT_MAX_SIZE;
        if (strlen($responseBody) > $maxResponseSize) {
            throw new RuntimeException(sprintf(
                'Response size (%d bytes) exceeds limit (%d bytes)',
                strlen($responseBody),
                $maxResponseSize,
            ));
        }

        // Parse response headers from $http_response_header
        $responseHeaders = $this->parseResponseHeaders($http_response_header ?? []);
        $statusCode = $responseHeaders[':status'] ?? 200;

        return [
            'status' => (int) $statusCode,
            'headers' => $responseHeaders,
            'body' => $responseBody,
            'url' => $url,
        ];
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

        $host = strtolower($parts['host']);

        // Check blocked hostnames
        foreach (self::BLOCKED_HOSTS as $blocked) {
            if ($host === $blocked || str_ends_with($host, '.' . $blocked)) {
                throw new RuntimeException('Blocked host: ' . $host);
            }
        }

        // Check blocked IP prefixes (private/reserved ranges)
        $ip = gethostbynamel($host);
        if ($ip !== false) {
            foreach ($ip as $addr) {
                foreach (self::BLOCKED_PREFIXES as $prefix) {
                    if (str_starts_with($addr, $prefix)) {
                        throw new RuntimeException('Blocked IP range for host: ' . $host . ' (' . $addr . ')');
                    }
                }
                if (in_array($addr, ['127.0.0.1', '::1', '0.0.0.0'], true)) {
                    throw new RuntimeException('Blocked localhost IP for host: ' . $host);
                }
            }
        }
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