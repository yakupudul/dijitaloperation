<?php

namespace App\Services\Integrations\WordPress;

use App\Models\CoreConnection;
use Carbon\CarbonImmutable;
use Closure;
use GuzzleHttp\Psr7\Response as PsrResponse;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Connector 1.13.0 "site işi kendisi alır" (Avrupadent 2026-10-09: its host refuses every request from the MoxDOP
 * server, IPv4 and IPv6, even the home page). The request MoxDOP would send is stored as a command; the plugin asks
 * for its commands on its own schedule (POST /api/connectors/wordpress/commands, signed like the events), runs each
 * one inside WordPress through the same signed REST route (same permission check, same signed answer) and posts the
 * answer back. The caller waits for that answer, so every connector call works the same way over this path.
 */
final class WordPressConnectorCommands
{
    /** Commands handed to the site per exchange. */
    public const int PER_EXCHANGE = 3;

    /** A command the site took but did not answer within this many seconds is failed. */
    public const int ANSWER_SECONDS = 600;

    /** A command no site took within this many seconds is failed (the caller stopped waiting long ago). */
    public const int PICKUP_SECONDS = 1800;

    /** @param  Closure(): void|null  $tick  runs between two checks for the answer (sleeps by default) */
    public function __construct(private ?Closure $tick = null) {}

    /** The plugin asked for commands within the last day (1.13.0+ on the site). */
    public static function available(CoreConnection $connection): bool
    {
        $seen = data_get($connection->config, 'pull_seen_at');

        return is_string($seen) && CarbonImmutable::parse($seen)->gt(now()->subDay());
    }

    /**
     * Stores the request and waits for the site's answer.
     *
     * @param  array<string, scalar>  $query
     * @return array{response: Response, nonce: string}
     */
    public function call(CoreConnection $connection, string $method, string $route, array $query, string $payload, int $waitSeconds): array
    {
        ksort($query, SORT_STRING);
        $id = DB::table('website_connector_commands')->insertGetId([
            'connection_id' => $connection->id, 'method' => $method, 'route' => $route,
            'query' => $query === [] ? null : json_encode($query, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            'body' => $payload === '' ? null : $payload, 'status' => 'pending', 'created_at' => now(),
        ]);
        $deadline = microtime(true) + max(1, $waitSeconds);
        do {
            $row = DB::table('website_connector_commands')->find($id);
            if ($row !== null && $row->status === 'done') {
                return ['response' => new Response(new PsrResponse((int) $row->response_status, ['Content-Type' => 'application/json'], (string) $row->response_body)),
                    'nonce' => (string) $row->nonce];
            }
            if ($row === null || $row->status === 'failed') {
                throw new RuntimeException('Site işi alamadı: '.($row->error ?? 'iş bulunamadı').'.');
            }
            ($this->tick ?? fn () => usleep(500_000))();
        } while (microtime(true) < $deadline);
        // Not taken yet: withdrawn so the site never runs it after the caller gave up.
        $withdrawn = DB::table('website_connector_commands')->where('id', $id)->where('status', 'pending')
            ->update(['status' => 'failed', 'error' => 'zaman aşımı', 'finished_at' => now()]);

        throw new RuntimeException($withdrawn > 0
            ? 'Site işleri MoxDOP\'tan kendisi alıyor ama '.$waitSeconds.' saniyede gelip almadı. Sitede WordPress zamanlanmış görevleri (wp-cron) ziyaretle çalışır; birkaç dakika sonra tekrar dene.'
            : 'Site işi aldı ama cevabı '.$waitSeconds.' saniyede gelmedi; sonucu sitede kontrol et.');
    }

    /**
     * One exchange with the site: stores the answers it brought and hands out the next commands, freshly signed.
     *
     * @param  list<array{id: int, status: int, body: string}>  $results
     * @return list<array<string, mixed>>
     */
    public function exchange(CoreConnection $connection, string $clientId, string $secret, array $results, bool $take): array
    {
        $connection->forceFill(['config' => array_merge((array) $connection->config, ['pull_seen_at' => now()->toIso8601String()])])->save();
        foreach ($results as $result) {
            DB::table('website_connector_commands')->where('connection_id', $connection->id)->where('id', (int) $result['id'])->where('status', 'sent')
                ->update(['status' => 'done', 'response_status' => (int) $result['status'], 'response_body' => (string) $result['body'], 'finished_at' => now()]);
        }
        DB::table('website_connector_commands')->where('connection_id', $connection->id)->where('status', 'sent')
            ->where('sent_at', '<', now()->subSeconds(self::ANSWER_SECONDS))->update(['status' => 'failed', 'error' => 'site cevap vermedi', 'finished_at' => now()]);
        DB::table('website_connector_commands')->where('connection_id', $connection->id)->where('status', 'pending')
            ->where('created_at', '<', now()->subSeconds(self::PICKUP_SECONDS))->update(['status' => 'failed', 'error' => 'site almadı', 'finished_at' => now()]);
        DB::table('website_connector_commands')->where('finished_at', '<', now()->subDays(2))->delete();
        if (! $take) {
            return [];
        }
        $commands = [];
        foreach (DB::table('website_connector_commands')->where('connection_id', $connection->id)->where('status', 'pending')->orderBy('id')->limit(self::PER_EXCHANGE)->get() as $row) {
            $query = $row->query !== null ? (array) json_decode((string) $row->query, true) : [];
            $body = (string) ($row->body ?? '');
            $timestamp = (string) CarbonImmutable::now('UTC')->getTimestamp();
            $nonce = (string) Str::uuid();
            $canonical = implode("\n", [$row->method, $row->route, http_build_query($query, '', '&', PHP_QUERY_RFC3986), $timestamp, $nonce, hash('sha256', $body)]);
            $taken = DB::table('website_connector_commands')->where('id', $row->id)->where('status', 'pending')
                ->update(['status' => 'sent', 'nonce' => $nonce, 'sent_at' => now()]);
            if ($taken === 0) {
                continue;
            }
            $commands[] = ['id' => (int) $row->id, 'method' => (string) $row->method, 'route' => (string) $row->route,
                'query' => http_build_query($query, '', '&', PHP_QUERY_RFC3986), 'body' => $body,
                'headers' => [
                    WordPressConnectorClient::HEADER_CLIENT => $clientId, WordPressConnectorClient::HEADER_TIMESTAMP => $timestamp,
                    WordPressConnectorClient::HEADER_NONCE => $nonce, WordPressConnectorClient::HEADER_SIGNATURE => hash_hmac('sha256', $canonical, $secret),
                ]];
        }

        return $commands;
    }
}
