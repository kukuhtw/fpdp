<?php

declare(strict_types=1);

namespace App\Core\Http;

final class Response
{
    /**
     * @param array<string, string> $headers
     */
    private function __construct(
        public readonly int $status,
        public readonly array $headers,
        public readonly string $body,
    ) {
    }

    public static function html(string $body, int $status = 200): self
    {
        return new self($status, ['Content-Type' => 'text/html; charset=utf-8'], $body);
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function json(array $data, int $status = 200): self
    {
        $body = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return new self($status, ['Content-Type' => 'application/json'], $body === false ? '{}' : $body);
    }

    public static function noContent(): self
    {
        return new self(204, [], '');
    }

    public static function redirect(string $url, int $status = 302): self
    {
        return new self($status, ['Location' => $url], '');
    }

    public static function binary(string $content, string $contentType, string $filename): self
    {
        return new self(200, [
            'Content-Type' => $contentType,
            'Content-Disposition' => 'attachment; filename="' . str_replace('"', '', $filename) . '"',
            'Content-Length' => (string) strlen($content),
        ], $content);
    }

    public function send(): void
    {
        http_response_code($this->status);

        foreach ($this->headers as $name => $value) {
            header($name . ': ' . $value);
        }

        echo $this->body;
    }
}
