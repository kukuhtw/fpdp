<?php

declare(strict_types=1);

namespace App\Core\Http;

final class Request
{
    /**
     * @param array<string, mixed> $query
     * @param array<string, string> $headers Keys are lowercase header names.
     */
    public function __construct(
        public readonly string $method,
        public readonly string $path,
        public readonly array $query = [],
        public readonly ?string $body = null,
        public readonly array $headers = [],
        public readonly string $ipAddress = '0.0.0.0',
        public readonly bool $secure = true,
    ) {
    }

    public static function fromGlobals(): self
    {
        $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
        $uri = (string) ($_SERVER['REQUEST_URI'] ?? '/');
        $path = parse_url($uri, PHP_URL_PATH);

        parse_str((string) ($_SERVER['QUERY_STRING'] ?? ''), $query);

        $body = file_get_contents('php://input');

        return new self(
            $method,
            is_string($path) && $path !== '' ? $path : '/',
            $query,
            $body === false ? null : $body,
            self::headersFromGlobals(),
            (string) ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0'),
            self::isSecureFromGlobals(),
        );
    }

    /**
     * Whether the inbound request itself arrived over TLS — directly, or via
     * a reverse proxy that sets X-Forwarded-Proto (e.g. behind Dokploy/nginx
     * terminating TLS in front of PHP). Used only for building links back to
     * this same request's own content (e.g. an uploaded media URL); it must
     * NOT be used for anything with federation or webhook-callback semantics,
     * which stay hardcoded to https since a real deployment is expected to
     * always be reachable over TLS for those.
     */
    private static function isSecureFromGlobals(): bool
    {
        $https = (string) ($_SERVER['HTTPS'] ?? '');
        if ($https !== '' && strtolower($https) !== 'off') {
            return true;
        }

        return strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';
    }

    public function scheme(): string
    {
        return $this->secure ? 'https' : 'http';
    }

    /**
     * @return array<string, string>
     */
    private static function headersFromGlobals(): array
    {
        $headers = [];

        foreach ($_SERVER as $key => $value) {
            if (is_string($key) && str_starts_with($key, 'HTTP_') && is_string($value)) {
                $name = strtolower(str_replace('_', '-', substr($key, 5)));
                $headers[$name] = $value;
            }
        }

        if (isset($_SERVER['CONTENT_TYPE']) && is_string($_SERVER['CONTENT_TYPE'])) {
            $headers['content-type'] = $_SERVER['CONTENT_TYPE'];
        }

        return $headers;
    }

    public function header(string $name): ?string
    {
        return $this->headers[strtolower($name)] ?? null;
    }

    public function bearerToken(): ?string
    {
        $authorization = $this->header('authorization');
        if ($authorization === null || !preg_match('/^Bearer\s+(\S+)$/i', $authorization, $matches)) {
            return null;
        }

        return $matches[1];
    }

    /**
     * @return array<string, mixed>|null
     */
    public function json(): ?array
    {
        if ($this->body === null || $this->body === '') {
            return null;
        }

        $decoded = json_decode($this->body, true);

        return is_array($decoded) ? $decoded : null;
    }
}
