<?php

namespace App\Http\Controllers\Integrations;

use App\Models\CoreConnection;
use App\Services\Integrations\WordPress\WordPressConnectorPairingService;
use App\Support\Integrations\WordPress\WordPressConnectorCanonicalJson;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

final class WordPressConnectorEventsController
{
    public function __invoke(Request $request): JsonResponse
    {
        abort_if(strlen($request->getContent()) > 256 * 1024, 413);
        $installation = (string) $request->header('X-MoxDOP-Installation', '');
        $client = (string) $request->header('X-MoxDOP-Client', '');
        $nonce = (string) $request->header('X-MoxDOP-Nonce', '');
        $timestamp = (string) $request->header('X-MoxDOP-Timestamp', '');
        $provided = strtolower((string) $request->header('X-MoxDOP-Signature', ''));
        abort_unless(preg_match('/^[a-f0-9-]{36}$/i', $installation)
            && preg_match('/^[a-f0-9-]{36}$/i', $nonce)
            && ctype_digit($timestamp) && abs(time() - (int) $timestamp) <= 300
            && preg_match('/^[a-f0-9]{64}$/', $provided), 401);

        $connection = CoreConnection::query()->with('credential')
            ->where('type', WordPressConnectorPairingService::CONNECTION_TYPE)
            ->where('enabled', true)->where('config->pairing_state', 'paired')
            ->where('config->installation_id', $installation)->get()
            ->first(fn (CoreConnection $candidate): bool => hash_equals((string) data_get($candidate->credential?->encrypted_payload, 'client_id', ''), $client));
        $credentials = $connection?->credential?->encrypted_payload;
        abort_unless(is_array($credentials) && is_string($credentials['shared_secret'] ?? null)
            && hash_equals((string) ($credentials['client_id'] ?? ''), $client), 401);
        $secret = $credentials['shared_secret'];
        $canonical = implode("\n", ['POST', '/api/connectors/wordpress/events', '', $timestamp, $nonce, hash('sha256', $request->getContent())]);
        abort_unless(hash_equals(hash_hmac('sha256', $canonical, $secret), $provided), 401);

        $input = Validator::make($request->json()->all(), [
            'schema_version' => ['required', 'integer', 'in:1'],
            'installation_id' => ['required', Rule::in([$installation])],
            'plugin_version' => ['required', 'string', 'max:32'],
            'events' => ['present', 'array', 'max:50'],
            'events.*' => ['array:event_id,type,occurred_at,object_type,object_id,title,url,actor_id,actor_name,origin,fields'],
            'events.*.event_id' => ['required', 'uuid', 'distinct'],
            'events.*.type' => ['required', Rule::in([
                'content.created', 'content.updated', 'content.published', 'content.trashed',
                'content.unpublished', 'content.deleted', 'content.fields_updated', 'seo.updated',
                'settings.updated', 'maintenance.update_completed', 'maintenance.update_failed',
                'maintenance.activated', 'maintenance.deactivated', 'maintenance.theme_changed', 'access.role_changed',
            ])],
            'events.*.occurred_at' => ['required', 'date', 'before_or_equal:'.now()->addMinutes(5)->toIso8601String()],
            'events.*.object_type' => ['required', 'string', 'max:64'],
            'events.*.object_id' => ['required', 'string', 'max:191'],
            'events.*.title' => ['present', 'nullable', 'string', 'max:200'],
            'events.*.url' => ['present', 'nullable', 'string', 'max:2048'],
            'events.*.actor_id' => ['required', 'string', 'max:32'],
            'events.*.actor_name' => ['present', 'nullable', 'string', 'max:100'],
            'events.*.origin' => ['required', Rule::in(['wordpress_user', 'wordpress_automation'])],
            'events.*.fields' => ['required', 'array', 'max:50'],
            'events.*.fields.*' => ['string', 'max:100'],
            'delivery' => ['required', 'array'],
            'delivery.pending' => ['nullable', 'integer', 'min:0', 'max:100000'],
            'delivery.gap_at' => ['nullable', 'date'],
            'delivery.storage_ready' => ['required', 'boolean'],
            'delivery.wp_cron_disabled' => ['required', 'boolean'],
        ])->validate();

        $ids = DB::transaction(function () use ($connection, $input, $nonce, $client): array {
            $locked = CoreConnection::query()->with('credential')->whereKey($connection->id)->lockForUpdate()->firstOrFail();
            abort_unless($locked->enabled && data_get($locked->config, 'pairing_state') === 'paired'
                && hash_equals((string) data_get($locked->credential?->encrypted_payload, 'client_id', ''), $client), 401);
            abort_if(DB::table('website_connector_nonces')->where('connection_id', $locked->id)->where('nonce', $nonce)->exists(), 409);
            DB::table('website_connector_nonces')->insert(['connection_id' => $locked->id, 'nonce' => $nonce, 'created_at' => now()]);
            $ids = [];
            foreach ($input['events'] as $event) {
                $ids[] = $event['event_id'];
                if (DB::table('website_connector_events')->where('connection_id', $locked->id)->where('event_id', $event['event_id'])->exists()) {
                    continue;
                }
                DB::table('website_connector_events')->insert([
                    'connection_id' => $locked->id, 'digital_asset_id' => $locked->digital_asset_id,
                    'event_id' => $event['event_id'], 'type' => $event['type'],
                    'object_type' => $event['object_type'], 'object_id' => $event['object_id'],
                    'title' => $event['title'], 'actor_name' => $event['actor_name'], 'origin' => $event['origin'],
                    'payload' => json_encode($event, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                    'occurred_at' => CarbonImmutable::parse($event['occurred_at'])->utc(),
                    'received_at' => now(),
                ]);
            }
            DB::table('website_connector_delivery')->updateOrInsert(['connection_id' => $locked->id], [
                'last_received_at' => now(), 'plugin_version' => $input['plugin_version'],
                'pending_count' => $input['delivery']['pending'] ?? null,
                'gap_at' => empty($input['delivery']['gap_at']) ? null : CarbonImmutable::parse($input['delivery']['gap_at'])->utc(),
                'delivery' => json_encode([
                    'storage_ready' => $input['delivery']['storage_ready'],
                    'wp_cron_disabled' => $input['delivery']['wp_cron_disabled'],
                ], JSON_THROW_ON_ERROR),
                'latest_event_id' => DB::table('website_connector_events')->where('connection_id', $locked->id)->max('id') ?? 0,
            ]);
            return $ids;
        });

        $data = ['accepted_event_ids' => $ids];
        $time = time();
        return response()->json(['data' => $data, 'meta' => [
            'server_time' => $time, 'request_nonce' => $nonce,
            'signature' => hash_hmac('sha256', implode("\n", [(string) $time, $nonce,
                hash('sha256', (new WordPressConnectorCanonicalJson)->encode($data)),
            ]), $secret),
        ]]);
    }
}
