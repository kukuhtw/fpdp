<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Exceptions\UnauthorizedException;
use App\Core\Http\JsonEnvelope;
use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Services\Auth\AuthService;
use App\Services\Cv\CvAccessService;
use App\Services\Cv\CvDocumentService;
use App\Services\Profile\ProfileService;
use App\Services\Visitor\VisitorAuthService;

final class CvController
{
    public function __construct(
        private readonly AuthService $auth,
        private readonly ProfileService $profiles,
        private readonly VisitorAuthService $visitorAuth,
        private readonly CvDocumentService $documents,
        private readonly CvAccessService $access,
    ) {
    }

    /**
     * Owner uploads (or replaces) their node's CV.
     */
    public function upload(Request $request): Response
    {
        $context = $this->auth->authenticate($request->bearerToken());
        $document = $this->documents->upload((int) $context['node']['id'], $request->json() ?? []);

        return JsonEnvelope::success(self::documentPayload($document), 201);
    }

    /**
     * Public metadata: title, price, and whether the caller already has access.
     * Never returns the file itself and never requires sign-in.
     *
     * @param array<string, string> $params
     */
    public function show(Request $request, array $params): Response
    {
        $profile = $this->profiles->getPublicProfile($params['handle']);
        $visitorId = $this->optionalVisitorId($request);

        $result = $this->access->describe((int) $profile['node_id'], $visitorId);

        return JsonEnvelope::success([
            ...self::documentPayload($result['document']),
            'has_access' => $result['has_access'],
        ]);
    }

    /**
     * Visitor pays (if priced) and is granted access. Requires Google sign-in.
     *
     * @param array<string, string> $params
     */
    public function grantAccess(Request $request, array $params): Response
    {
        $profile = $this->profiles->getPublicProfile($params['handle']);
        $visitor = $this->requireVisitor($request);

        $result = $this->access->grantAccess((int) $profile['node_id'], (int) $visitor['id'], (string) $visitor['email']);

        return JsonEnvelope::success($result);
    }

    /**
     * Streams the CV file. Requires Google sign-in and, for a priced CV, an
     * existing grant (created via grantAccess()) — it never charges here.
     *
     * @param array<string, string> $params
     */
    public function download(Request $request, array $params): Response
    {
        $profile = $this->profiles->getPublicProfile($params['handle']);
        $visitor = $this->requireVisitor($request);

        $file = $this->access->readForDownload((int) $profile['node_id'], (int) $visitor['id']);

        return Response::binary($file['content'], $file['content_type'], $file['filename']);
    }

    /**
     * @return array<string, mixed>
     */
    private function requireVisitor(Request $request): array
    {
        $context = $this->visitorAuth->authenticate($request->bearerToken());

        return $context['visitor'];
    }

    private function optionalVisitorId(Request $request): ?int
    {
        $token = $request->bearerToken();
        if ($token === null) {
            return null;
        }

        try {
            return (int) $this->visitorAuth->authenticate($token)['visitor']['id'];
        } catch (UnauthorizedException) {
            return null;
        }
    }

    /**
     * @param array<string, mixed> $document
     * @return array<string, mixed>
     */
    private static function documentPayload(array $document): array
    {
        return [
            'id' => $document['public_id'],
            'title' => $document['title'],
            'price_amount' => $document['price_amount'],
            'price_currency' => $document['price_currency'],
        ];
    }
}
