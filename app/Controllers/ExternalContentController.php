<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Exceptions\ValidationException;
use App\Core\Http\JsonEnvelope;
use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Services\Auth\AuthService;
use App\Services\External\SyncWorker;
use App\Repositories\ExternalFeedSourceRepository;
use App\Repositories\ExternalPostRepository;

final class ExternalContentController
{
    public function __construct(
        private readonly AuthService $auth,
        private readonly SyncWorker $syncWorker,
        private readonly ExternalFeedSourceRepository $feedSources,
        private readonly ExternalPostRepository $externalPosts,
    ) {
    }

    public function listFeedSources(Request $request): Response
    {
        $context = $this->auth->authenticate($request->bearerToken());
        $sources = $this->feedSources->findAllByUserId((int) $context['user']['id']);
        return JsonEnvelope::success(['sources' => $sources]);
}
public function listExternalPosts(Request $request): Response
    {
        $limit = filter_var($request->query['limit'] ?? 20, FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1, 'max_range' => 100],
        ]);
        $limit = $limit === false ? 20 : $limit;

        $beforeId = null;
        if (isset($request->query['cursor']) && $request->query['cursor'] !== '') {
            $decoded = base64_decode(strtr((string) $request->query['cursor'], '-_', '+/'), true);
            if ($decoded !== false && ctype_digit($decoded)) {
                $beforeId = (int) $decoded;
            }
        }

        $rows = $this->externalPosts->listPublic($limit, $beforeId);
        $hasMore = count($rows) > $limit;
        if ($hasMore) array_pop($rows);

        $last = $rows === [] ? null : $rows[array_key_last($rows)];

        return JsonEnvelope::collection(
            $rows,
            $hasMore && $last !== null ? rtrim(strtr(base64_encode((string) $last['id']), '+/', '-_'), '=') : null,
            $hasMore,
        );
    }

    public function addFeedSource(Request $request): Response
    {
        $context = $this->auth->authenticate($request->bearerToken());
        $input = $request->json() ?? [];

        $errors = [];
        if (empty($input['provider'])) $errors[] = ['field' => 'provider', 'reason' => 'required'];
        if (empty($input['source_url'])) $errors[] = ['field' => 'source_url', 'reason' => 'required'];
        if (empty($input['source_type'])) $errors[] = ['field' => 'source_type', 'reason' => 'required'];
        if (!in_array($input['provider'] ?? null, ['RSS', 'ATOM', 'CUSTOM_API'], true)) {
            $errors[] = ['field' => 'provider', 'reason' => 'invalid_value'];
        }

        if ($errors !== []) {
            throw new ValidationException($errors);
        }

        $id = $this->feedSources->create(
            (int) $context['user']['id'],
            strtoupper((string) $input['provider']),
            (string) $input['source_type'],
            (string) $input['source_url'],
            isset($input['external_account_id']) ? (int) $input['external_account_id'] : null,
            (int) ($input['sync_interval'] ?? 3600),
        );

        return JsonEnvelope::success(['id' => $id], 201);
    }

    public function triggerSync(Request $request): Response
    {
        $this->auth->authenticate($request->bearerToken());
        $stats = $this->syncWorker->run();
        return JsonEnvelope::success([
            'processed' => $stats['processed'],
            'inserted' => $stats['inserted'],
            'errors' => $stats['errors'],
        ]);
    }

    public function stats(Request $request): Response
    {
        $this->auth->authenticate($request->bearerToken());
        $totalPosts = $this->externalPosts->getTotalCount();
        return JsonEnvelope::success(['total_posts' => $totalPosts]);
    }
}