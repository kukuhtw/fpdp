<?php

declare(strict_types=1);

namespace App\Repositories;

use PDO;

/**
 * Append-only event log behind the Analytics dashboard: profile views,
 * content views, outbound-link clicks, and shop conversions. Never stores
 * a raw IP address — only a daily-rotating HMAC hash (see AnalyticsService).
 */
final class AnalyticsEventRepository
{
    public function __construct(private readonly PDO $connection)
    {
    }

    public function record(
        int $nodeId,
        string $eventType,
        ?string $subjectType,
        ?string $subjectPublicId,
        ?string $visitorHash,
    ): void {
        $statement = $this->connection->prepare(
            'INSERT INTO analytics_events (node_id, event_type, subject_type, subject_public_id, visitor_hash, occurred_on)
             VALUES (:node_id, :event_type, :subject_type, :subject_public_id, :visitor_hash, :occurred_on)',
        );
        $statement->execute([
            'node_id' => $nodeId,
            'event_type' => $eventType,
            'subject_type' => $subjectType,
            'subject_public_id' => $subjectPublicId,
            'visitor_hash' => $visitorHash,
            'occurred_on' => gmdate('Y-m-d'),
        ]);
    }

    /**
     * One row per UTC day in [today - $days + 1, today] that has at least
     * one PROFILE_VIEW/POST_VIEW event. Days with zero events are simply
     * absent — AnalyticsService fills the gaps for a continuous chart.
     *
     * @return array<int, array{occurred_on: string, unique_visitors: int, views: int}>
     */
    public function dailyTraffic(int $nodeId, string $sinceDate): array
    {
        $statement = $this->connection->prepare(
            "SELECT occurred_on, COUNT(DISTINCT visitor_hash) AS unique_visitors, COUNT(*) AS views
             FROM analytics_events
             WHERE node_id = :node_id AND occurred_on >= :since_date
               AND event_type IN ('PROFILE_VIEW', 'POST_VIEW')
             GROUP BY occurred_on
             ORDER BY occurred_on ASC",
        );
        $statement->execute(['node_id' => $nodeId, 'since_date' => $sinceDate]);

        return $statement->fetchAll();
    }

    public function countByType(int $nodeId, string $eventType, string $sinceDate): int
    {
        $statement = $this->connection->prepare(
            'SELECT COUNT(*) FROM analytics_events
             WHERE node_id = :node_id AND event_type = :event_type AND occurred_on >= :since_date',
        );
        $statement->execute(['node_id' => $nodeId, 'event_type' => $eventType, 'since_date' => $sinceDate]);

        return (int) $statement->fetchColumn();
    }

    /**
     * Most-viewed content (POST_VIEW events), most views first.
     *
     * @return array<int, array{subject_type: string, subject_public_id: string, views: int}>
     */
    public function topContent(int $nodeId, int $limit = 5): array
    {
        $statement = $this->connection->prepare(
            "SELECT subject_type, subject_public_id, COUNT(*) AS views
             FROM analytics_events
             WHERE node_id = :node_id AND event_type = 'POST_VIEW' AND subject_public_id IS NOT NULL
             GROUP BY subject_type, subject_public_id
             ORDER BY views DESC
             LIMIT :limit",
        );
        $statement->bindValue('node_id', $nodeId, PDO::PARAM_INT);
        $statement->bindValue('limit', $limit, PDO::PARAM_INT);
        $statement->execute();

        return $statement->fetchAll();
    }
}
