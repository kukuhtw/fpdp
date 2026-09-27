<?php

declare(strict_types=1);

namespace App\Services\Federation;

use App\Core\Exceptions\ConflictException;
use App\Repositories\ProfileRepository;

/**
 * Signs an outbound node-to-node request as this node's owner actor
 * (https://{domain}/@{handle}, key #main-key) — the same identity and
 * draft-cavage HTTP Signature the federation delivery worker uses, so the
 * receiving node verifies it with the public key in our actor document.
 */
final class SignedRequestSigner
{
    public function __construct(
        private readonly NodeKeyService $keys,
        private readonly ProfileRepository $profiles,
    ) {
    }

    /** This node's owner actor URI — who the signed requests come from. */
    public function actorUri(int $nodeId): string
    {
        $profile = $this->profiles->findByNodeId($nodeId);
        if ($profile === null) {
            throw new ConflictException('This node has no owner profile yet.');
        }

        return 'https://' . $profile['node_domain'] . '/@' . $profile['handle'];
    }

    /**
     * Headers for the request (Host, Date, [Digest], Signature), to send
     * together with any Content-Type/Accept the caller adds.
     *
     * @return array<string, string>
     */
    public function headers(int $nodeId, string $method, string $url, ?string $body = null): array
    {
        $method = strtoupper($method);
        if (!$this->keys->hasKey($nodeId)) {
            $this->keys->generateKeypair($nodeId);
        }

        $host = (string) parse_url($url, PHP_URL_HOST);
        $target = (string) (parse_url($url, PHP_URL_PATH) ?: '/');
        $query = parse_url($url, PHP_URL_QUERY);
        if (is_string($query) && $query !== '') {
            $target .= '?' . $query;
        }

        $headers = ['host' => $host, 'date' => HttpSignature::httpDate()];
        $signed = ['(request-target)', 'host', 'date'];
        if ($body !== null) {
            $headers['digest'] = HttpSignature::digestHeader($body);
            $signed[] = 'digest';
        }

        $signature = $this->keys->sign($nodeId, HttpSignature::buildSigningString($method, $target, $headers, $signed));
        $out = ['Host' => $host, 'Date' => $headers['date']];
        if (isset($headers['digest'])) {
            $out['Digest'] = $headers['digest'];
        }
        $out['Signature'] = HttpSignature::buildSignatureHeader($this->actorUri($nodeId) . '#main-key', $signed, $signature);

        return $out;
    }
}
