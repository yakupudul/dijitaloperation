<?php

namespace App\Http\Controllers\Integrations;

use App\Models\CoreConnection;
use App\Services\Integrations\WordPress\WordPressConnectorCommands;
use App\Services\Integrations\WordPress\WordPressConnectorPairingService;
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
            && ctype_digit($timestamp) && abs(time() - (int) $timestamp) <= 300
            && preg_match('/^[a-f0-9]{64}$/', $provided), 401);

        $connection = CoreConnection::query()->with('credential')
            ->where('type', WordPressConnectorPairingService::CONNECTION_TYPE)
            ->where('enabled', true)->where('config->pairing_state', 'paired')
            ->where('config->installation_id', $installation)->get()
            ->first(fn (CoreConnection $candidate): bool => hash_equals((string) data_get($candidate->credential?->encrypted_payload, 'client_id', ''), $client));
        $credentials = $connection?->credential?->encrypted_payload;
        abort_unless($connection !== null && is_array($credentials) && is_string($credentials['shared_secret'] ?? null), 401);
        $secret = $credentials['shared_secret'];
        $canonical = implode("\n", ['POST', self::PATH, '', $timestamp, $nonce, hash('sha256', $request->getContent())]);
        abort_unless(hash_equals(hash_hmac('sha256', $canonical, $secret), $provided), 401);
        abort_if(DB::table('website_connector_nonces')->where('connection_id', $connection->id)->where('nonce', $nonce)->exists(), 409);
        DB::table('website_connector_nonces')->insert(['connection_id' => $connection->id, 'nonce' => $nonce, 'created_at' => now()]);

        $input = Validator::make($request->json()->all(), [
            'schema_version' => ['required', 'integer', 'in:1'],
            'installation_id' => ['required', Rule::in([$installation])],
            'plugin_version' => ['required', 'string', 'max:32'],
            'take' => ['required', 'boolean'],
            'results' => ['present', 'array', 'max:'.WordPressConnectorCommands::PER_EXCHANGE * 2],
            'results.*.id' => ['required', 'integer'],
            'results.*.status' => ['required', 'integer', 'between:100,599'],
            'results.*.body' => ['present', 'string'],
        ])->validate();

        $handed = $commands->exchange($connection, $client, $secret, array_values($input['results']), (bool) $input['take']);
        $until = microtime(true) + self::HOLD_SECONDS;
        while ($handed === [] && $input['take'] && microtime(true) < $until
            && data_get($connection->config, 'rest_transport') === 'pull' && ! app()->runningUnitTests()) {
            usleep(500_000);
            $handed = $commands->exchange($connection, $client, $secret, [], true);
        }

        $data = ['commands' => $handed, 'pull' => data_get($connection->fresh()?->config, 'rest_transport') === 'pull'];
        $time = time();

        return response()->json(['data' => $data, 'meta' => [
            'server_time' => $time, 'request_nonce' => $nonce,
            'signature' => hash_hmac('sha256', implode("\n", [(string) $time, $nonce,
                hash('sha256', (new WordPressConnectorCanonicalJson)->encode($data)),
            ]), $secret),
        ]]);
    }
}
