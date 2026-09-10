<?php

namespace App\Http\Controllers\Integrations;

use App\Models\CoreIntegration;
use App\Models\WhatsAppWebhookReceipt;
use App\Services\WhatsApp\WhatsAppConnection;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;

final class WhatsAppWebhookController
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

    public function receive(Request $request, WhatsAppConnection $connection): Response
    {
        $integration = $connection->integration();
        abort_unless($integration?->isActive(), 503);
        $raw = $request->getContent();
        abort_if(strlen($raw) > 4 * 1024 * 1024, 413);
        DB::transaction(function () use ($request, $connection, $integration, $raw): void {
            $integration = CoreIntegration::query()->lockForUpdate()->findOrFail($integration->id);
            abort_unless($integration->isActive(), 503);
            $secret = (string) ($connection->secrets($integration)['app_secret'] ?? '');
            $signature = (string) $request->header('X-Hub-Signature-256', '');
            abort_unless($secret !== '' && hash_equals('sha256='.hash_hmac('sha256', $raw, $secret), $signature), 403);
            $payload = json_decode($raw, true);
            abort_unless(is_array($payload) && ($payload['object'] ?? null) === 'whatsapp_business_account'
                && is_array($payload['entry'] ?? null), 422);

            WhatsAppWebhookReceipt::query()->firstOrCreate([
                'integration_id' => $integration->id,
                'payload_hash' => hash('sha256', $raw),
            ], ['payload' => $payload, 'status' => 'pending']);
        });

        // Durable receipt first; the scheduler dispatches work even if Redis is temporarily down.
        return response('EVENT_RECEIVED', 200)->header('Content-Type', 'text/plain');
    }
}

