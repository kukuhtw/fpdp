<?php

declare(strict_types=1);

namespace App\Core\Http;

final class Request
{
    /**
     * @param array<string, mixed> $query
     */
    public function __construct(
        public readonly string $method,
        public readonly string $path,
        public readonly array $query = [],
        public readonly ?string $body = null,
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
        );
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
