<?php

declare(strict_types=1);

namespace App\Services\Dashboard;

use App\Repositories\AuditEventRepository;
use App\Repositories\ExternalPostRepository;
use App\Repositories\FederatedPostRepository;
use App\Repositories\NodeRepository;
use App\Repositories\OrderRepository;
use App\Repositories\PaymentRepository;
use App\Repositories\PostRepository;
use App\Repositories\ProductRepository;
use App\Services\Federation\FederationService;

/**
 * Read-only aggregator for the owner dashboard's Overview page. Combines
 * counts that already exist elsewhere (content, commerce, federation,
 * audit trail) rather than tracking anything new itself.
 *
 * Deliberately does NOT report profile views, content reach, or outbound
 * clicks: FPDP has no visitor-analytics tracking anywhere yet (see the
 * "analytics" block in getOverview()'s response) — that is a separate,
 * much larger feature, not something this aggregator can honestly fake.
 */
final class DashboardService
{
    public function __construct(
        private readonly NodeRepository $nodes,
        private readonly PostRepository $posts,
        private readonly ExternalPostRepository $externalPosts,
        private readonly FederatedPostRepository $federatedPosts,
        private readonly ProductRepository $products,
        private readonly OrderRepository $orders,
        private readonly PaymentRepository $payments,
        private readonly FederationService $federation,
        private readonly AuditEventRepository $auditEvents,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function getOverview(int $userId, int $profileId, int $nodeId): array
    {
        $node = $this->nodes->findById($nodeId);
        $postCounts = $this->posts->countByProfileId($profileId);
        $federationSummary = $this->federation->getFederationSummary($profileId, $nodeId);
        $paymentSummary = $this->payments->getSummary();

        return [
            'node' => [
                'name' => $node['name'] ?? null,
                'domain' => $node['domain'] ?? null,
                'status' => $node['status'] ?? null,
            ],
            'content' => $postCounts,
            'timeline_mix' => [
                'local' => $postCounts['published'],
                'external' => $this->externalPosts->countByUserId($userId),
                'federated' => $this->federatedPosts->countForProfile($profileId),
            ],
            'commerce' => [
                'product_count' => count($this->products->listByNodeId($nodeId)),
                'order_count' => $this->orders->countByNodeId($nodeId),
                'pending_order_count' => $this->orders->countByNodeId($nodeId, 'PENDING'),
                'revenue_this_month' => $paymentSummary['revenue_this_month'],
                'currency' => $paymentSummary['currency'],
            ],
            'federation' => [
                'follower_count' => $federationSummary['follower_count'],
                'following_count' => $federationSummary['following_count'],
            ],
            'recent_activity' => $this->recentActivity($nodeId),
            'analytics' => [
                'available' => false,
                'reason' => 'No visitor-analytics tracking exists yet (profile views, content reach); this is future work.',
            ],
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function recentActivity(int $nodeId, int $limit = 10): array
    {
        $events = $this->auditEvents->findByNodeId($nodeId, $limit);

        return array_map(static function (array $event): array {
            return [
                'action' => $event['action'],
                'subject_type' => $event['subject_type'],
                'subject_public_id' => $event['subject_public_id'],
                'occurred_at' => $event['created_at'],
            ];
        }, $events);
    }
}
