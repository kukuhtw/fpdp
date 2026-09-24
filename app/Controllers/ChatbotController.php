<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Http\JsonEnvelope;
use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Services\Auth\AuthService;
use App\Services\Chatbot\ChatbotService;
use App\Services\Chatbot\VisitorWalletService;
use App\Services\Profile\ProfileService;
use App\Services\Visitor\VisitorAuthService;

final class ChatbotController
{
    public function __construct(
        private readonly AuthService $auth,
        private readonly ProfileService $profiles,
        private readonly VisitorAuthService $visitorAuth,
        private readonly ChatbotService $chatbot,
        private readonly VisitorWalletService $wallet,
    ) {
    }

    // ---- Owner-facing ----

    public function getSettings(Request $request): Response
    {
        $context = $this->auth->authenticate($request->bearerToken());

        return JsonEnvelope::success($this->chatbot->getPublicSettings((int) $context['node']['id']));
    }

    public function updateSettings(Request $request): Response
    {
        $context = $this->auth->authenticate($request->bearerToken());
        $input = $request->json() ?? [];

        return JsonEnvelope::success($this->chatbot->updateSettings(
            (int) $context['node']['id'],
            (string) ($input['price_per_question'] ?? '0'),
            (string) ($input['currency'] ?? 'IDR'),
            (bool) ($input['enabled'] ?? false),
        ));
    }

    public function listVisitors(Request $request): Response
    {
        $context = $this->auth->authenticate($request->bearerToken());

        return JsonEnvelope::success($this->wallet->listVisitorsWithBalances((int) $context['node']['id']));
    }

    /**
     * @param array<string, string> $params
     */
    public function grantDeposit(Request $request, array $params): Response
    {
        $context = $this->auth->authenticate($request->bearerToken());
        $input = $request->json() ?? [];
        $wallet = $this->wallet->ownerGrant(
            (int) $context['node']['id'],
            $params['visitorId'],
            (float) ($input['amount'] ?? 0),
            isset($input['note']) ? (string) $input['note'] : null,
        );

        return JsonEnvelope::success($wallet);
    }

    // ---- Visitor-facing ----

    /**
     * GET /api/v1/profiles/{handle}/chatbot/settings — public, no auth, so
     * the profile page can show pricing before the visitor signs in.
     *
     * @param array<string, string> $params
     */
    public function publicSettings(Request $request, array $params): Response
    {
        $profile = $this->profiles->getPublicProfile($params['handle']);

        return JsonEnvelope::success($this->chatbot->getPublicSettings((int) $profile['node_id']));
    }

    /**
     * @param array<string, string> $params
     */
    public function getWallet(Request $request, array $params): Response
    {
        $profile = $this->profiles->getPublicProfile($params['handle']);
        $visitor = $this->requireVisitor($request);
        $settings = $this->chatbot->getPublicSettings((int) $profile['node_id']);

        return JsonEnvelope::success($this->chatbot->getWalletBalance((int) $visitor['id'], $settings['currency']));
    }

    /**
     * @param array<string, string> $params
     */
    public function topUp(Request $request, array $params): Response
    {
        $profile = $this->profiles->getPublicProfile($params['handle']);
        $visitor = $this->requireVisitor($request);
        $input = $request->json() ?? [];

        $result = $this->wallet->topUp((int) $profile['node_id'], $visitor, (float) ($input['amount'] ?? 0));

        return JsonEnvelope::success([
            'credited' => $result['credited'],
            'payment' => $result['payment'],
            'wallet_balance' => $result['wallet']['balance_amount'],
        ], 201);
    }

    /**
     * @param array<string, string> $params
     */
    public function ask(Request $request, array $params): Response
    {
        $profile = $this->profiles->getPublicProfile($params['handle']);
        $visitor = $this->requireVisitor($request);
        $input = $request->json() ?? [];

        return JsonEnvelope::success(
            $this->chatbot->ask((int) $profile['node_id'], $visitor, (string) ($input['question'] ?? '')),
            201,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function requireVisitor(Request $request): array
    {
        return $this->visitorAuth->authenticate($request->bearerToken())['visitor'];
    }
}
