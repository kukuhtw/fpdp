<?php

declare(strict_types=1);

namespace App\Services\Security;

use App\Repositories\AuditEventRepository;

/**
 * Records security and operational events to the audit_events table.
 * All services that perform user-visible actions should call AuditService
 * so the node operator has a tamper-evident log of changes.
 */
final class AuditService
{
    public function __construct(private readonly AuditEventRepository $audit)
    {
    }

    /**
     * @param array<string, mixed> $context Contains 'user' and 'node' keys from auth context.
     * @param array<string, mixed>|null $metadata Optional additional data (will be JSON-encoded, no secrets).
     */
    public function record(
        array $context,
        string $action,
        ?string $subjectType = null,
        ?string $subjectPublicId = null,
        ?array $metadata = null,
    ): void {
        // A failed audit write must not undo or block the action it
        // describes (a refund already sent to the gateway, a login), so it
        // is logged loudly instead of thrown.
        try {
            $this->audit->create(
                (int) ($context['node']['id'] ?? 0),
                isset($context['user']['id']) ? (int) $context['user']['id'] : null,
                $action,
                $subjectType,
                $subjectPublicId,
                $this->sanitizeMetadata($metadata ?? []),
            );
        } catch (\Throwable $exception) {
            error_log("[audit] failed to record {$action}: " . $exception->getMessage());
        }
    }

    /**
     * @param array<string, mixed> $metadata
     * @return array<string, mixed>
     */
    private function sanitizeMetadata(array $metadata): array
    {
        $sanitized = [];
        foreach ($metadata as $key => $value) {
            // Skip keys that might contain sensitive data
            if (in_array($key, ['password', 'password_hash', 'access_token', 'token', 'secret', 'authorization'], true)) {
                continue;
            }
            $sanitized[$key] = $value;
        }
        return $sanitized;
    }
}