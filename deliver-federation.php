#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * FPDP Federation Delivery Worker — CLI entry point for cron.
 *
 * Processes pending outgoing ActivityPub activities: signs each one with a
 * real HTTP Signature (RFC draft-cavage — the header-based scheme Mastodon
 * and the rest of the Fediverse verify, not a field embedded in the JSON
 * body) and POSTs it to the target actor's actual inbox URL.
 *
 * Usage:
 *   php deliver-federation.php                       # process all pending (default: 10)
 *   php deliver-federation.php --max=5               # process max 5 activities
 *
 * Add to crontab every 5 minutes (remove the space between stars):
 *   star/5 * * * * /usr/bin/php /var/www/fpdp/deliver-federation.php
 */

require_once __DIR__ . '/vendor/autoload.php';

use App\Core\Config;
use App\Core\Database;
use App\Core\Http\HttpClient;
use App\Repositories\FederationActivityRepository;
use App\Repositories\NodeKeyRepository;
use App\Repositories\ProfileRepository;
use App\Repositories\RemoteActorRepository;
use App\Repositories\RemoteNodeRepository;
use App\Services\Federation\HttpSignature;
use App\Services\Federation\NodeDiscoveryService;
use App\Services\Federation\NodeKeyService;

$options = getopt('', ['max::']);
$maxActivities = isset($options['max']) ? (int) $options['max'] : 10;

Config::load(__DIR__ . '/.env');
$connection = Database::connection();
$activityRepo = new FederationActivityRepository($connection);
$keyService = new NodeKeyService(new NodeKeyRepository($connection));
$profiles = new ProfileRepository($connection);
$actors = new RemoteActorRepository($connection);
$http = new HttpClient();
$discovery = new NodeDiscoveryService(new RemoteNodeRepository($connection), $actors, $http);

$pending = $activityRepo->findPendingOutgoing($maxActivities);
$timestamp = date('Y-m-d H:i:s');
$delivered = 0;
$failed = 0;

foreach ($pending as $activity) {
    $nodeId = (int) $activity['node_id'];
    $payload = json_decode((string) $activity['payload'], true);
    $targetActorUri = (string) ($activity['target_actor_uri'] ?? '');

    if ($payload === null) {
        $activityRepo->markFailed((int) $activity['id'], 'Invalid payload JSON');
        $failed++;
        continue;
    }
    if ($targetActorUri === '') {
        $activityRepo->markFailed((int) $activity['id'], 'No target actor URI');
        $failed++;
        continue;
    }

    // The target actor's inbox is resolved once (by sendFollowByAccount()/
    // approveFollowRequest() etc. before queuing) and cached on
    // remote_actors — normally already fresh here, this is a defensive
    // fallback for an activity queued before the actor was ever resolved.
    $targetActor = $actors->findByActorUri($targetActorUri) ?? $discovery->resolveActorByUri($targetActorUri);
    $inboxUrl = $targetActor['inbox_url'] ?? null;
    if (!is_string($inboxUrl) || $inboxUrl === '') {
        $activityRepo->markFailed((int) $activity['id'], "Could not resolve an inbox URL for {$targetActorUri}");
        $failed++;
        continue;
    }

    $localProfile = $profiles->findByNodeId($nodeId);
    if ($localProfile === null) {
        $activityRepo->markFailed((int) $activity['id'], 'No local profile for this node');
        $failed++;
        continue;
    }
    $keyId = 'https://' . $localProfile['node_domain'] . '/@' . $localProfile['handle'] . '#main-key';

    $host = (string) parse_url($inboxUrl, PHP_URL_HOST);
    $path = (string) parse_url($inboxUrl, PHP_URL_PATH);
    $body = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if ($body === false) {
        $activityRepo->markFailed((int) $activity['id'], 'Could not encode payload as JSON');
        $failed++;
        continue;
    }

    $headers = [
        'host' => $host,
        'date' => HttpSignature::httpDate(),
        'digest' => HttpSignature::digestHeader($body),
    ];

    try {
        $signingString = HttpSignature::buildSigningString('POST', $path, $headers, HttpSignature::DEFAULT_SIGNED_HEADERS);
        $signature = $keyService->sign($nodeId, $signingString);
        $signatureHeader = HttpSignature::buildSignatureHeader($keyId, HttpSignature::DEFAULT_SIGNED_HEADERS, $signature);
    } catch (\Throwable $e) {
        $activityRepo->markFailed((int) $activity['id'], 'Signing error: ' . $e->getMessage());
        $failed++;
        continue;
    }

    try {
        $response = $http->request('POST', $inboxUrl, [
            'Host' => $host,
            'Date' => $headers['date'],
            'Digest' => $headers['digest'],
            'Signature' => $signatureHeader,
            'Content-Type' => 'application/activity+json',
            'Accept' => 'application/activity+json',
        ], $body);

        if ($response['status'] >= 200 && $response['status'] < 300) {
            $activityRepo->markDelivered((int) $activity['id']);
            $delivered++;
        } else {
            $activityRepo->markFailed((int) $activity['id'], "HTTP {$response['status']}");
            $failed++;
        }
    } catch (\Throwable $e) {
        $activityRepo->markFailed((int) $activity['id'], 'Delivery error: ' . $e->getMessage());
        $failed++;
    }
}

fwrite(STDOUT, "[{$timestamp}] Federation delivery: {$delivered} delivered, {$failed} failed\n");
