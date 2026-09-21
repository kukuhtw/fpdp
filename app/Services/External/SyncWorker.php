<?php

declare(strict_types=1);

namespace App\Services\External;

use App\Repositories\ExternalFeedSourceRepository;
use App\Repositories\ExternalPostRepository;
use App\Repositories\IntegrationQueueRepository;
use App\Core\Uuid;
use PDO;

/**
 * Worker that fetches external feed sources due for sync,
 * calls the appropriate connector, deduplicates, and persists results.
 */
final class SyncWorker
{
    private const MAX_SOURCES_PER_RUN = 10;

    public function __construct(
        private readonly ExternalFeedSourceRepository $feedSources,
        private readonly ExternalPostRepository $externalPosts,
        private readonly ExternalConnectorFactory $factory = new ExternalConnectorFactory(),
    ) {
    }

    /**
     * Run one sync cycle: fetch all sources due for sync.
     *
     * @return array{processed: int, inserted: int, errors: int}
     */
    public function run(int $maxSources = self::MAX_SOURCES_PER_RUN): array
    {
        $sources = $this->feedSources->findDueForSync($maxSources);
        $stats = ['processed' => 0, 'inserted' => 0, 'errors' => 0];

        foreach ($sources as $source) {
            $stats['processed']++;
            $result = $this->syncSource($source);

            if ($result['success']) {
                $this->feedSources->updateSyncStatus(
                    (int) $source['id'],
                    'ACTIVE',
                    null,
                    (int) ($source['sync_interval'] ?? 3600),
                );
                $stats['inserted'] += $result['inserted'];
            } else {
                $this->feedSources->updateSyncStatus(
                    (int) $source['id'],
                    'ERROR',
                    $result['error'],
                    (int) ($source['sync_interval'] ?? 3600),
                );
                $stats['errors']++;
            }
        }

        return $stats;
    }

    /** Force-sync the authenticated owner's enabled sources, regardless of next_sync_at. */
    public function runForUser(int $userId, int $maxSources = self::MAX_SOURCES_PER_RUN): array
    {
        $sources = $this->feedSources->findAllForSyncByUserId($userId, $maxSources);
        $stats = ['processed' => 0, 'inserted' => 0, 'errors' => 0];
        foreach ($sources as $source) {
            $stats['processed']++;
            $result = $this->syncSource($source);
            $this->feedSources->updateSyncStatus(
                (int) $source['id'],
                $result['success'] ? 'ACTIVE' : 'ERROR',
                $result['error'],
                (int) ($source['sync_interval'] ?? 3600),
            );
            if ($result['success']) {
                $stats['inserted'] += $result['inserted'];
            } else {
                $stats['errors']++;
            }
        }
        return $stats;
    }

    /**
     * Sync a single feed source.
     *
     * @param array<string, mixed> $source
     * @return array{success: bool, inserted: int, error: ?string}
     */
    public function syncSource(array $source): array
    {
        try {
            $connector = $this->factory->create(
                $source['provider'],
                ['source_url' => $source['source_url']],
            );
        } catch (\Throwable $e) {
            return ['success' => false, 'inserted' => 0, 'error' => 'Connector error: ' . $e->getMessage()];
        }

        $account = [
            'source_url' => $source['source_url'],
            'access_token' => $source['access_token'] ?? null,
            'provider_account_id' => $source['provider_account_id'] ?? null,
            'account_display_name' => $source['account_display_name'] ?? null,
        ];

        $result = $connector->fetchPosts($account);

        if (isset($result['error']) && $result['error'] !== null && ($result['items'] ?? []) === []) {
            return ['success' => false, 'inserted' => 0, 'error' => $result['error']];
        }

        $inserted = 0;
        foreach ($result['items'] ?? [] as $item) {
            $externalId = $item['external_id'] ?? uniqid('ext-', true);

            // Dedup by (provider, external_post_id)
            if ($this->externalPosts->exists($source['provider'], $externalId)) {
                continue;
            }

            $publishedAt = null;
            if (!empty($item['published_at'])) {
                try {
                    $ts = strtotime((string) $item['published_at']);
                    $publishedAt = $ts !== false ? date('Y-m-d H:i:s', $ts) : null;
                } catch (\Throwable) {
                    $publishedAt = null;
                }
            }

            $this->externalPosts->create(
                (int) $source['user_id'],
                $source['provider'],
                $externalId,
                (int) $source['id'],
                $item['type'] ?? 'ARTICLE',
                $item['canonical_url'] ?? null,
                null, // title - extracted from text or kept null
                $item['text'] ?? null,
                $item['media'] ?? null,
                $item['author']['display_name'] ?? $item['author']['username'] ?? null,
                $publishedAt,
            );
            $inserted++;
        }

        return ['success' => true, 'inserted' => $inserted, 'error' => null];
    }
}
