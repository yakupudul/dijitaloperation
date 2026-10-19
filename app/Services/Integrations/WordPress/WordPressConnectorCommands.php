<?php

namespace App\Services\Integrations\WordPress;

use App\Models\CoreConnection;
use App\Models\ExternalWriteAction;
use App\Services\ExternalWrites\ExternalWriteService;
use Carbon\CarbonImmutable;
use Closure;
use GuzzleHttp\Psr7\Response as PsrResponse;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
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

    /** Commands waiting for the site above which new writes wait for it instead of adding more (it takes 3 per ask). */
    public const int MAX_WAITING = 6;

    /** A command the site took but did not answer within this many seconds is failed. */
    public const int ANSWER_SECONDS = 600;

    /** A command no site took within this many seconds is failed (the caller stopped waiting long ago). */
    public const int PICKUP_SECONDS = 1800;

    /**
     * Requests that stay waiting for the site after the caller stopped waiting, with how long (seconds from creation).
     * A connector update a site that rarely asks would otherwise never get; its package link lives 15 minutes.
     */
    public const array LATE_PICKUP_SECONDS = ['/moxdop/v1/self-update' => 840];

    /** A call made inside a web request (screen, MCP) waits at most this long for the site. */
    public const int WEB_WAIT_SECONDS = 45;

    /** @param  Closure(): void|null  $tick  runs between two checks for the answer (sleeps by default) */
    public function __construct(private ?Closure $tick = null) {}

    /** The plugin asked for commands within the last day (1.13.0+ on the site). */
    public static function available(CoreConnection $connection): bool
    {
        return self::seenAt($connection)?->gt(now()->subDay()) ?? false;
    }

    /**
     * When the site last asked. Kept outside the connection's config, which long-running jobs save back from their own
     * copy; since 1.16.0 in the delivery row (the cache alone lost it and the page said "never asked").
     */
    public static function seenAt(CoreConnection $connection): ?CarbonImmutable
    {
        $stored = DB::table('website_connector_delivery')->where('connection_id', $connection->id)->value('pull_seen_at');
        $cached = Cache::get(self::seenKey($connection));
        $times = array_filter([
            $stored !== null ? CarbonImmutable::parse((string) $stored) : null,
            is_string($cached) ? CarbonImmutable::parse($cached) : null,
        ]);

        return $times === [] ? null : max($times);
    }

    /**
     * What the operator needs to see about a site that fetches its work itself: when it last asked, how it asked
     * (wp-cron or a page view), what it reported, why MoxDOP last refused an ask and the work waiting for it.
     *
     * @return array{transport: string, seen_at: ?string, report: array<string, mixed>, refused_at: ?string, refusal: ?string, pending: int, sent: int, failed_last_hour: int}
     */
    public static function state(CoreConnection $connection): array
    {
        $row = DB::table('website_connector_delivery')->where('connection_id', $connection->id)->first(['pull_report', 'pull_refused_at', 'pull_refusal']);
        $counts = DB::table('website_connector_commands')->where('connection_id', $connection->id)
            ->selectRaw("sum(case when status = 'pending' then 1 else 0 end) as pending, sum(case when status = 'sent' then 1 else 0 end) as sent")
            ->selectRaw("sum(case when status = 'failed' and finished_at >= ? then 1 else 0 end) as failed", [now()->subHour()])->first();
        $report = $row?->pull_report !== null ? json_decode((string) $row->pull_report, true) : null;

        return [
            'transport' => (string) data_get($connection->config, 'rest_transport', 'path'),
            'seen_at' => self::seenAt($connection)?->toIso8601String(),
            'report' => is_array($report) ? $report : [],
            'refused_at' => $row?->pull_refused_at !== null ? CarbonImmutable::parse((string) $row->pull_refused_at)->toIso8601String() : null,
            'refusal' => $row?->pull_refusal,
            'pending' => (int) ($counts->pending ?? 0),
            'sent' => (int) ($counts->sent ?? 0),
            'failed_last_hour' => (int) ($counts->failed ?? 0),
        ];
    }

    /** "son soruşu 3 dakika önce" / "hiç sormadı", for messages. */
    public static function lastAskText(CoreConnection $connection): string
    {
        $seen = self::seenAt($connection);

        return $seen !== null ? 'site en son '.$seen->diffForHumans().' sordu' : 'site henüz hiç sormadı';
    }

    /** MoxDOP refused an ask of an identified site (clock, signature, replay, malformed): kept so the page can say why. */
    public static function refused(CoreConnection $connection, string $reason): void
    {
        DB::table('website_connector_delivery')->updateOrInsert(['connection_id' => $connection->id],
            ['pull_refused_at' => now(), 'pull_refusal' => mb_substr($reason, 0, 200)]);
    }

    /** Sets how MoxDOP reaches the site, in the stored config itself (not from a possibly old copy) and on this copy. */
    public static function setTransport(CoreConnection $connection, string $transport): void
    {
        CoreConnection::query()->whereKey($connection->id)->update(['config->rest_transport' => $transport]);
        $connection->setRawAttributes(array_merge($connection->getAttributes(), ['config' => json_encode(array_merge((array) $connection->config, ['rest_transport' => $transport]))]), true);
    }

    private static function seenKey(CoreConnection $connection): string
    {
        return 'wordpress-connector:pull-seen:'.$connection->id;
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
        if (! app()->runningInConsole()) {
            // A screen or MCP call stops waiting before the web server's 60 s gateway timeout (it answered 504 before).
            $waitSeconds = min($waitSeconds, self::WEB_WAIT_SECONDS);
        }
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
        if (isset(self::LATE_PICKUP_SECONDS[$route])) {
            throw new RuntimeException('Site bu isteği henüz almadı ('.self::lastAskText($connection).'); '
                .intdiv(self::LATE_PICKUP_SECONDS[$route], 60).' dakika boyunca ilk soruşunda alacak. Sayfadaki eklenti sürümü değişince tamamdır.');
        }
        // Not taken yet: withdrawn so the site never runs it after the caller gave up.
        $withdrawn = DB::table('website_connector_commands')->where('id', $id)->where('status', 'pending')
            ->update(['status' => 'failed', 'error' => 'zaman aşımı', 'finished_at' => now()]);

        if ($withdrawn > 0) {
            throw new SiteDidNotTakeWork('Site işleri MoxDOP\'tan kendisi alıyor ama '.$waitSeconds.' saniyede gelip almadı ('.self::lastAskText($connection).'). Eklenti 1.16.0+ sitede bir ziyaret olunca da sorar; birkaç dakika sonra tekrar dene.');
        }

        throw new RuntimeException('Site işi aldı ama cevabı '.$waitSeconds.' saniyede gelmedi; sonucu sitede kontrol et.');
    }

    /**
     * Work still on its way to the site: commands not answered yet and writes waiting for it (ExternalWriteService::
     * waitForSite). 1.18.0 keeps asking while this is above zero instead of waiting for the next visit.
     */
    public static function waiting(CoreConnection $connection): int
    {
        $commands = DB::table('website_connector_commands')->where('connection_id', $connection->id)->whereIn('status', ['pending', 'sent'])->count();

        return $commands + self::waitingWritesQuery($connection)->count();
    }

    /**
     * 1.16.0+ beacon addresses of the sites that fetch their work themselves and have work on its way: every MoxDOP
     * screen pings them from the operator's browser (which the site's host lets through), so approved work goes out
     * while the operator is in MoxDOP even when the site has no visitors. Cached for 30 seconds.
     *
     * @return list<string>
     */
    public static function wakeUrls(): array
    {
        return Cache::remember('wordpress-connector:wake-urls', 30, fn (): array => CoreConnection::query()->where('type', 'wordpress_connector')
            ->where('enabled', true)->where('config->rest_transport', 'pull')->get()
            ->filter(fn (CoreConnection $connection): bool => self::waiting($connection) > 0)
            ->map(function (CoreConnection $connection): ?string {
                $snapshot = (string) data_get($connection->config, 'snapshot_url', '');
                $base = str_contains($snapshot, '/wp-json/') ? strstr($snapshot, '/wp-json/', true) : (string) strtok($snapshot, '?');

                return str_starts_with($base, 'https://') ? rtrim($base, '/').'/wp-admin/admin-ajax.php?action=moxdop_tick' : null;
            })->filter()->values()->all());
    }

    /** @return Builder<ExternalWriteAction> */
    private static function waitingWritesQuery(CoreConnection $connection): Builder
    {
        return ExternalWriteAction::query()->where('channel', ExternalWriteAction::CHANNEL_WORDPRESS)->where('digital_asset_id', $connection->digital_asset_id)
            ->where('status', 'queued')->where('error', 'like', ExternalWriteService::SITE_WAIT_ERROR.'%');
    }

    /**
     * The site is asking: the writes that wait for it are tried now, oldest first, as many as fit next to the work it
     * already has (MAX_WAITING), instead of at their next ten-minute turn. Once a minute per site at most.
     */
    private static function wakeWaitingWrites(CoreConnection $connection): void
    {
        if ($connection->digital_asset_id === null || ! Cache::add('wordpress-connector:wake:'.$connection->id, 1, 20)) {
            return;
        }
        $room = self::MAX_WAITING - DB::table('website_connector_commands')->where('connection_id', $connection->id)->whereIn('status', ['pending', 'sent'])->count();
        if ($room < 1) {
            return;
        }
        $service = app(ExternalWriteService::class);
        self::waitingWritesQuery($connection)->orderBy('id')->limit($room)->get()->each(fn (ExternalWriteAction $write) => $service->wakeForSite($write));
    }

    /**
     * One exchange with the site: stores the answers it brought and hands out the next commands, freshly signed.
     *
     * @param  list<array{id: int, status: int, body: string}>  $results
     * @param  array<string, mixed>|null  $report  what the plugin says about itself (1.16.0+): version, trigger, cron, last failure
     * @return list<array<string, mixed>>
     */
    public function exchange(CoreConnection $connection, string $clientId, string $secret, array $results, bool $take, ?array $report = null): array
    {
        Cache::put(self::seenKey($connection), now()->toIso8601String(), now()->addDays(30));
        if ($report !== null) {
            // Once per ask (the held re-checks of the same ask pass null).
            DB::table('website_connector_delivery')->updateOrInsert(['connection_id' => $connection->id], ['pull_seen_at' => now()]
                + ($report !== [] ? ['pull_report' => json_encode($report, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)] : []));
        }
        foreach ($results as $result) {
            DB::table('website_connector_commands')->where('connection_id', $connection->id)->where('id', (int) $result['id'])->where('status', 'sent')
                ->update(['status' => 'done', 'response_status' => (int) $result['status'], 'response_body' => (string) $result['body'], 'finished_at' => now()]);
        }
        DB::table('website_connector_commands')->where('connection_id', $connection->id)->where('status', 'sent')
            ->where('sent_at', '<', now()->subSeconds(self::ANSWER_SECONDS))->update(['status' => 'failed', 'error' => 'site cevap vermedi', 'finished_at' => now()]);
        DB::table('website_connector_commands')->where('connection_id', $connection->id)->where('status', 'pending')
            ->where('created_at', '<', now()->subSeconds(self::PICKUP_SECONDS))->update(['status' => 'failed', 'error' => 'site almadı', 'finished_at' => now()]);
        foreach (self::LATE_PICKUP_SECONDS as $lateRoute => $seconds) {
            DB::table('website_connector_commands')->where('connection_id', $connection->id)->where('status', 'pending')->where('route', $lateRoute)
                ->where('created_at', '<', now()->subSeconds($seconds))->update(['status' => 'failed', 'error' => 'site almadı', 'finished_at' => now()]);
        }
        DB::table('website_connector_commands')->where('finished_at', '<', now()->subDays(2))->delete();
        if (! $take) {
            return [];
        }
        if ($report !== null) {
            self::wakeWaitingWrites($connection);
        }
        $commands = [];
        foreach (DB::table('website_connector_commands')->where('connection_id', $connection->id)->where('status', 'pending')
            // A connector update goes first: a site with hundreds of waiting fixes would otherwise take it half an hour later.
            ->orderByRaw("case when route = '/moxdop/v1/self-update' then 0 else 1 end")->orderBy('id')->limit(self::PER_EXCHANGE)->get() as $row) {
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
