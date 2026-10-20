<?php

namespace App\Http\Controllers\Integrations;

use App\Models\CoreConnection;
use App\Services\Integrations\WordPress\WordPressConnectorCommands;
use App\Services\Integrations\WordPress\WordPressConnectorPairingService;
use App\Services\Site\BrandlessSites;
use App\Support\Integrations\WordPress\WordPressConnectorCanonicalJson;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/**
 * Connector 1.13.0: the site fetches the requests MoxDOP could not deliver (host refuses MoxDOP's server) and brings
 * the answers of the ones it ran. Signed by the plugin like the events; the answer is signed by MoxDOP the same way.
 * When nothing waits, the request is held for a few seconds so a sync that sends request after request keeps moving.
 */
final class WordPressConnectorCommandsController
{
    public const string PATH = '/api/connectors/wordpress/commands';

    /** Seconds an exchange waits for a new command when none is pending. */
    public const int HOLD_SECONDS = 8;

    public function __invoke(Request $request, WordPressConnectorCommands $commands): JsonResponse
    {
        abort_if(strlen($request->getContent()) > 24 * 1024 * 1024, 413);
        $installation = (string) $request->header('X-MoxDOP-Installation', '');
        $client = (string) $request->header('X-MoxDOP-Client', '');
        $nonce = (string) $request->header('X-MoxDOP-Nonce', '');
        $timestamp = (string) $request->header('X-MoxDOP-Timestamp', '');
        $provided = strtolower((string) $request->header('X-MoxDOP-Signature', ''));
        abort_unless(preg_match('/^[a-f0-9-]{36}$/i', $installation)
            && preg_match('/^[a-f0-9-]{36}$/i', $nonce)
            && ctype_digit($timestamp)
            && preg_match('/^[a-f0-9]{64}$/', $provided), 401);

        $connection = CoreConnection::query()->with('credential')
            ->where('type', WordPressConnectorPairingService::CONNECTION_TYPE)
            ->where('enabled', true)->where('config->pairing_state', 'paired')
            ->where('config->installation_id', $installation)->get()
            ->first(fn (CoreConnection $candidate): bool => hash_equals((string) data_get($candidate->credential?->encrypted_payload, 'client_id', ''), $client));
        $credentials = $connection?->credential?->encrypted_payload;
        abort_unless($connection !== null && is_array($credentials) && is_string($credentials['shared_secret'] ?? null), 401);
        // From here the site is known: every refusal is kept with its reason (the page showed only "never asked").
        $skew = time() - (int) $timestamp;
        if (abs($skew) > 300) {
            WordPressConnectorCommands::refused($connection, 'Sitenin saati MoxDOP\'tan '.abs($skew).' saniye '.($skew > 0 ? 'geri' : 'ileri').'; imzalı istek reddedildi. Sunucu saati düzeltilmeli.');
            abort(401);
        }
        $secret = $credentials['shared_secret'];
        $canonical = implode("\n", ['POST', self::PATH, '', $timestamp, $nonce, hash('sha256', $request->getContent())]);
        if (! hash_equals(hash_hmac('sha256', $canonical, $secret), $provided)) {
            WordPressConnectorCommands::refused($connection, 'İmza tutmadı (istek yolda değişmiş ya da eklenti anahtarı farklı; eklentiyi yeniden eşleştirmek gerekebilir).');
            abort(401);
        }
        if (DB::table('website_connector_nonces')->where('connection_id', $connection->id)->where('nonce', $nonce)->exists()) {
            WordPressConnectorCommands::refused($connection, 'Aynı istek ikinci kez geldi (tekrar), reddedildi.');
            abort(409);
        }
        DB::table('website_connector_nonces')->insert(['connection_id' => $connection->id, 'nonce' => $nonce, 'created_at' => now()]);

        $validator = Validator::make($request->json()->all(), [
            'schema_version' => ['required', 'integer', 'in:1'],
            'installation_id' => ['required', Rule::in([$installation])],
            'plugin_version' => ['required', 'string', 'max:32'],
            'take' => ['required', 'boolean'],
            'results' => ['present', 'array', 'max:'.WordPressConnectorCommands::PER_EXCHANGE * 2],
            'results.*.id' => ['required', 'integer'],
            'results.*.status' => ['required', 'integer', 'between:100,599'],
            'results.*.body' => ['present', 'nullable', 'string'],
            // 1.16.0: how the site asked and what failed last time (shown on the connector page).
            'site' => ['sometimes', 'array'],
            'site.trigger' => ['sometimes', 'nullable', 'string', 'max:16'],
            'site.cron_disabled' => ['sometimes', 'nullable', 'boolean'],
            'site.last_failure' => ['sometimes', 'nullable', 'string', 'max:200'],
            'site.last_failure_at' => ['sometimes', 'nullable', 'string', 'max:40'],
        ]);
        if ($validator->fails()) {
            WordPressConnectorCommands::refused($connection, 'Eksik ya da bozuk istek: '.mb_substr(implode(' ', $validator->errors()->all()), 0, 150));
        }
        $input = $validator->validate();
        self::recordVersion($connection, (string) $input['plugin_version']);
        $report = array_filter([
            'plugin_version' => (string) $input['plugin_version'],
            'trigger' => $input['site']['trigger'] ?? null,
            'cron_disabled' => $input['site']['cron_disabled'] ?? null,
            'last_failure' => $input['site']['last_failure'] ?? null,
            'last_failure_at' => $input['site']['last_failure_at'] ?? null,
        ], fn ($value): bool => $value !== null);
        $results = array_map(fn (array $result): array => ['id' => (int) $result['id'], 'status' => (int) $result['status'], 'body' => (string) ($result['body'] ?? '')],
            array_values($input['results']));

        $handed = $commands->exchange($connection, $client, $secret, $results, (bool) $input['take'], $report);
        $until = microtime(true) + self::HOLD_SECONDS;
        while ($handed === [] && $input['take'] && microtime(true) < $until
            && data_get($connection->config, 'rest_transport') === 'pull' && ! app()->runningUnitTests()) {
            usleep(500_000);
            $handed = $commands->exchange($connection, $client, $secret, [], true, null);
        }

        // 1.18.0: work still on its way, so the site asks again at once instead of waiting for a visit (older plugins ignore it).
        // 1.21.0 (yakup 2026-10-20): a site without a brand is only being built, so the plugin tells no search engine
        // about its pages (IndexNow off) until it is given a brand (older plugins ignore it).
        $data = ['commands' => $handed, 'pull' => data_get($connection->fresh()?->config, 'rest_transport') === 'pull', 'waiting' => WordPressConnectorCommands::waiting($connection),
            'serviced' => $connection->digital_asset_id !== null && BrandlessSites::serviced((int) $connection->digital_asset_id)];
        $time = time();

        return response()->json(['data' => $data, 'meta' => [
            'server_time' => $time, 'request_nonce' => $nonce,
            'signature' => hash_hmac('sha256', implode("\n", [(string) $time, $nonce,
                hash('sha256', (new WordPressConnectorCanonicalJson)->encode($data)),
            ]), $secret),
        ]]);
    }

    /** The version the site reports on every ask (the page showed an old one while the host refused status reads). */
    private static function recordVersion(CoreConnection $connection, string $version): void
    {
        if (preg_match('/^\d+\.\d+\.\d+(?:[-+][a-zA-Z0-9.-]+)?$/', $version) !== 1 || data_get($connection->config, 'plugin_version') === $version) {
            return;
        }
        CoreConnection::query()->whereKey($connection->id)->update(['config->plugin_version' => $version]);
        DB::table('website_connector_delivery')->where('connection_id', $connection->id)->update(['plugin_version' => $version]);
    }
}
