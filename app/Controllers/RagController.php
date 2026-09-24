<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Http\JsonEnvelope;
use App\Core\Http\Request;
use App\Core\Http\Response;
use App\Services\Auth\AuthService;
use App\Services\Rag\RagService;

/**
 * Owner-only RAG document/FAQ management (no visitor-facing surface yet —
 * this feeds a later chatbot feature). Every endpoint requires the owner's
 * bearer token; there is nothing here for visitors to call.
 */
final class RagController
{
    public function __construct(
        private readonly AuthService $auth,
        private readonly RagService $rag,
    ) {
    }

    public function uploadDocument(Request $request): Response
    {
        $context = $this->auth->authenticate($request->bearerToken());
        $document = $this->rag->uploadDocument((int) $context['node']['id'], $request->json() ?? []);

        return JsonEnvelope::success(self::documentPayload($document), 201);
    }

    public function listDocuments(Request $request): Response
    {
        $context = $this->auth->authenticate($request->bearerToken());
        $documents = $this->rag->listDocuments((int) $context['node']['id']);

        return JsonEnvelope::success(array_map([self::class, 'documentPayload'], $documents));
    }

    /**
     * @param array<string, string> $params
     */
    public function deleteDocument(Request $request, array $params): Response
    {
        $context = $this->auth->authenticate($request->bearerToken());
        $this->rag->deleteDocument((int) $context['node']['id'], $params['documentId']);

        return Response::noContent();
    }

    /**
     * @param array<string, string> $params
     */
    public function listFaqs(Request $request, array $params): Response
    {
        $context = $this->auth->authenticate($request->bearerToken());
        $faqs = $this->rag->listFaqs((int) $context['node']['id'], $params['documentId']);

        return JsonEnvelope::success(array_map([self::class, 'faqPayload'], $faqs));
    }

    /**
     * @param array<string, string> $params
     */
    public function generateFaqs(Request $request, array $params): Response
    {
        $context = $this->auth->authenticate($request->bearerToken());
        $input = $request->json() ?? [];
        $faqs = $this->rag->generateFaqs((int) $context['node']['id'], $params['documentId'], (int) ($input['count'] ?? 5));

        return JsonEnvelope::success(array_map([self::class, 'faqPayload'], $faqs), 201);
    }

    /**
     * @param array<string, string> $params
     */
    public function updateFaq(Request $request, array $params): Response
    {
        $context = $this->auth->authenticate($request->bearerToken());
        $input = $request->json() ?? [];
        $faq = $this->rag->updateFaq(
            (int) $context['node']['id'],
            $params['faqId'],
            (string) ($input['question'] ?? ''),
            (string) ($input['answer'] ?? ''),
        );

        return JsonEnvelope::success(self::faqPayload($faq));
    }

    /**
     * @param array<string, string> $params
     */
    public function deleteFaq(Request $request, array $params): Response
    {
        $context = $this->auth->authenticate($request->bearerToken());
        $this->rag->deleteFaq((int) $context['node']['id'], $params['faqId']);

        return Response::noContent();
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
            'original_filename' => $document['original_filename'],
            'content' => $document['content'],
            'created_at' => $document['created_at'],
        ];
    }

    /**
     * @param array<string, mixed> $faq
     * @return array<string, mixed>
     */
    private static function faqPayload(array $faq): array
    {
        return [
            'id' => $faq['public_id'],
            'question' => $faq['question'],
            'answer' => $faq['answer'],
            'embedding_generated' => $faq['embedding'] !== null,
            'embedding_model' => $faq['embedding_model'],
        ];
    }
}
