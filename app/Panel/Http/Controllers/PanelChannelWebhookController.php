<?php

namespace App\Panel\Http\Controllers;

use App\Panel\Services\PanelChannels;
use App\Services\WhatsApp\WhatsAppConnection;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Where Meta posts the events of the brands' own channels (WhatsApp numbers, Instagram accounts, pages, lead forms).
 * Signed with the agency's Meta app secret; an event for an account no brand connected is answered and dropped.
 */
final class PanelChannelWebhookController
{
    public function verify(Request $request, WhatsAppConnection $connection): Response
    {
        $integration = $connection->integration();
        abort_unless($integration?->isActive(), 503);
        $token = (string) ($connection->secrets($integration)['verify_token'] ?? '');
        $mode = $request->query('hub_mode', $request->query('hub.mode'));
        $supplied = $request->query('hub_verify_token', $request->query('hub.verify_token', ''));
        $challenge = $request->query('hub_challenge', $request->query('hub.challenge', ''));
        abort_unless($mode === 'subscribe' && is_string($supplied) && $token !== '' && hash_equals($token, $supplied), 403);
        abort_unless(is_string($challenge) && preg_match('/^[0-9]{1,100}$/', $challenge), 400);

        return response($challenge, 200)->header('Content-Type', 'text/plain');
    }

    public function receive(Request $request, WhatsAppConnection $connection, PanelChannels $channels): Response
    {
        $integration = $connection->integration();
        abort_unless($integration?->isActive(), 503);
        $raw = $request->getContent();
        abort_if(strlen($raw) > 4 * 1024 * 1024, 413);
        $secret = (string) ($connection->secrets($integration)['app_secret'] ?? '');
        abort_unless($secret !== '' && hash_equals('sha256='.hash_hmac('sha256', $raw, $secret), (string) $request->header('X-Hub-Signature-256', '')), 403);
        $payload = json_decode($raw, true);
        abort_unless(is_array($payload) && is_array($payload['entry'] ?? null), 422);
        $channels->accept($payload, $raw);

        return response('EVENT_RECEIVED', 200)->header('Content-Type', 'text/plain');
    }
}
