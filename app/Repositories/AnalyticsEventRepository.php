<?php

declare(strict_types=1);

namespace App\Repositories;

use PDO;

/**
 * Append-only event log behind the Analytics dashboard: page views (public
 * HTML pages as PAGE_VIEW, plus the older PROFILE_VIEW/POST_VIEW), outbound
 * link clicks, and shop conversions. Never stores a raw IP address — only a
 * daily-rotating HMAC hash (see AnalyticsService) — and of a referrer only
 * its host.
 */
final class AnalyticsEventRepository
{
    /** Event types that count as someone looking at a page. */
    public const VIEW_TYPES = ['PAGE_VIEW', 'PROFILE_VIEW', 'POST_VIEW'];

    public function __construct(private readonly PDO $connection)
    {
    }

    public function record(
        int $nodeId,
        string $eventType,
        ?string $subjectType,
        ?string $subjectPublicId,
        ?string $visitorHash,
        ?string $referrerHost = null,
    ): void {
        $statement = $this->connection->prepare(
            'INSERT INTO analytics_events (node_id, event_type, subject_type, subject_public_id, visitor_hash, referrer_host, occurred_on)
             VALUES (:node_id, :event_type, :subject_type, :subject_public_id, :visitor_hash, :referrer_host, :occurred_on)',
        );
        $statement->execute([
            'node_id' => $nodeId,
            'event_type' => $eventType,
            'subject_type' => $subjectType,
            'subject_public_id' => $subjectPublicId,
            'visitor_hash' => $visitorHash,
            'referrer_host' => $referrerHost,
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
    public function dailyTraffic(int $nodeId, string $sinceDate, ?string $untilDate = null): array
    {
        $statement = $this->connection->prepare(
            "SELECT occurred_on, COUNT(DISTINCT visitor_hash) AS unique_visitors, COUNT(*) AS views
             FROM analytics_events
             WHERE node_id = :node_id AND occurred_on >= :since_date AND occurred_on <= :until_date
               AND event_type IN ('PAGE_VIEW', 'PROFILE_VIEW', 'POST_VIEW')
             GROUP BY occurred_on
             ORDER BY occurred_on ASC",
        );
        $statement->execute(['node_id' => $nodeId, 'since_date' => $sinceDate, 'until_date' => $untilDate ?? '9999-12-31']);

        return $statement->fetchAll();
    }

    public function countByType(int $nodeId, string $eventType, string $sinceDate, ?string $untilDate = null): int
    {
        $statement = $this->connection->prepare(
            'SELECT COUNT(*) FROM analytics_events
             WHERE node_id = :node_id AND event_type = :event_type AND occurred_on >= :since_date AND occurred_on <= :until_date',
        );
        $statement->execute(['node_id' => $nodeId, 'event_type' => $eventType, 'since_date' => $sinceDate, 'until_date' => $untilDate ?? '9999-12-31']);

        return (int) $statement->fetchColumn();
    }

    /**
     * Most-viewed pages in [since, until], with the post or product title
     * when the page is one. A post may be recorded by numeric id (HTML
     * page) or public id (JSON API); both resolve to the same post.
     *
     * @return array<int, array{event_type: string, subject_type: string|null, subject_public_id: string|null, views: int, visits: int, post_id: int|null, post_title: string|null, product_title: string|null}>
     */
    public function topPages(int $nodeId, string $sinceDate, string $untilDate, int $limit): array
    {
        $statement = $this->connection->prepare(
            "SELECT t.event_type, t.subject_type, t.subject_public_id, t.views, t.visits,
                    p.id AS post_id, p.title AS post_title, pr.title AS product_title
             FROM (
                 SELECT event_type, subject_type, subject_public_id, COUNT(*) AS views, COUNT(DISTINCT visitor_hash) AS visits
                 FROM analytics_events
                 WHERE node_id = :node_id AND occurred_on >= :since_date AND occurred_on <= :until_date
                   AND event_type IN ('PAGE_VIEW', 'PROFILE_VIEW', 'POST_VIEW')
                 GROUP BY event_type, subject_type, subject_public_id
             ) t
             LEFT JOIN posts p ON t.subject_type = 'post' AND (p.public_id = t.subject_public_id OR CAST(p.id AS CHAR) = t.subject_public_id)
             LEFT JOIN products pr ON t.subject_type = 'product' AND pr.public_id = t.subject_public_id
             ORDER BY t.views DESC, t.visits DESC
             LIMIT :limit",
        );
        $statement->bindValue('node_id', $nodeId, PDO::PARAM_INT);
        $statement->bindValue('since_date', $sinceDate);
        $statement->bindValue('until_date', $untilDate);
        $statement->bindValue('limit', $limit, PDO::PARAM_INT);
        $statement->execute();

        return $statement->fetchAll();
    }

    /**
     * Sites that sent visitors, by number of page views they led to.
     *
     * @return array<int, array{referrer_host: string, views: int, visits: int}>
     */
    public function topReferrers(int $nodeId, string $sinceDate, string $untilDate, int $limit): array
    {
        $statement = $this->connection->prepare(
            "SELECT referrer_host, COUNT(*) AS views, COUNT(DISTINCT visitor_hash) AS visits
             FROM analytics_events
             WHERE node_id = :node_id AND occurred_on >= :since_date AND occurred_on <= :until_date
               AND event_type IN ('PAGE_VIEW', 'PROFILE_VIEW', 'POST_VIEW') AND referrer_host IS NOT NULL
             GROUP BY referrer_host
             ORDER BY views DESC
             LIMIT :limit",
        );
        $statement->bindValue('node_id', $nodeId, PDO::PARAM_INT);
        $statement->bindValue('since_date', $sinceDate);
        $statement->bindValue('until_date', $untilDate);
        $statement->bindValue('limit', $limit, PDO::PARAM_INT);
        $statement->execute();

        return $statement->fetchAll();
    }

    /**
     * Outbound links visitors clicked, most clicked first.
     *
     * @return array<int, array{url: string, clicks: int}>
     */
    public function topOutbound(int $nodeId, string $sinceDate, string $untilDate, int $limit): array
    {
        $statement = $this->connection->prepare(
            "SELECT subject_public_id AS url, COUNT(*) AS clicks
             FROM analytics_events
             WHERE node_id = :node_id AND occurred_on >= :since_date AND occurred_on <= :until_date
               AND event_type = 'OUTBOUND_CLICK' AND subject_public_id IS NOT NULL
             GROUP BY subject_public_id
             ORDER BY clicks DESC
             LIMIT :limit",
        );
        $statement->bindValue('node_id', $nodeId, PDO::PARAM_INT);
        $statement->bindValue('since_date', $sinceDate);
        $statement->bindValue('until_date', $untilDate);
        $statement->bindValue('limit', $limit, PDO::PARAM_INT);
        $statement->execute();

        return $statement->fetchAll();
    }

    /**
     * Retention: deletes every event dated before $beforeDate. Returns how
     * many rows were removed.
     */
    public function deleteOlderThan(string $beforeDate): int
    {
        $statement = $this->connection->prepare('DELETE FROM analytics_events WHERE occurred_on < :before_date');
        $statement->execute(['before_date' => $beforeDate]);

        return $statement->rowCount();
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
