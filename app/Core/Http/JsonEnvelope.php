<?php

declare(strict_types=1);

namespace App\Core\Http;

/**
 * Builds response bodies matching the standard success/error envelopes
 * defined in documentation/API-CONTRACT.en.md.
 */
final class JsonEnvelope
{
    /**
     * @param array<string, mixed> $data
     */
    public static function success(array $data, int $status = 200): Response
    {
        return Response::json([
            'data' => $data,
            'meta' => ['request_id' => self::requestId()],
        ], $status);
    }

    public static function collection(array $data, ?string $nextCursor, bool $hasMore): Response
    {
        return Response::json([
            'data' => $data,
            'meta' => [
                'request_id' => self::requestId(),
                'next_cursor' => $nextCursor,
                'has_more' => $hasMore,
            ],
        ]);
    }

    /**
     * @param array<int, array<string, mixed>> $details
     */
    public static function error(string $code, string $message, int $status, array $details = []): Response
    {
        $error = [
            'code' => $code,
            'message' => $message,
            'request_id' => self::requestId(),
        ];

        if ($details !== []) {
            $error['details'] = $details;
        }

        return Response::json(['error' => $error], $status);
    }

    private static function requestId(): string
    {
        return 'req_' . bin2hex(random_bytes(8));
    }
}
