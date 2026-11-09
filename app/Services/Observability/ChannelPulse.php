<?php

namespace App\Services\Observability;

use App\Enums\Observability\OperationalAlertRuleType;
use App\Enums\Observability\OperationalAlertSeverity;
use App\Enums\Observability\OperationalSignalFamily;
use App\Models\SocialAccount;
use App\Services\WhatsApp\WhatsAppConnection;
use App\Support\SchemaState;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Kanal nabzı (kök neden planı madde 3, yakup 2026-11-09): a connection is healthy when real data keeps arriving, not
 * when the last call returned no error. WhatsApp said "Bağlı" while no message came for a day (8 Nov 14:02); a token
 * check cannot see that. For every inbound channel this reads when the last real incoming item reached MoxDOP and how
 * long the gaps between arrivals usually are (last 30 days), and calls the channel silent when the current gap is well
 * past the usual one. Silent → a critical alert (phone / browser push once per opening); data again → it closes.
 *
 * Arrival time is the row's created_at (when MoxDOP received it), never the message's own time: a history import
 * inserts old messages and must not look like fresh traffic. History rows are left out.
 */
final class ChannelPulse
{
    public const string RULE = 'channel_silent';

    public const string OK = 'ok';

    public const string SILENT = 'silent';

    /** Too little traffic to know what "usual" is: no alert, the screen says "bilinmiyor". */
    public const string UNKNOWN = 'unknown';

    public const int WINDOW_DAYS = 30;

    /** Fewer arrivals than this in the window: the usual gap cannot be measured. */
    public const int MIN_EVENTS = 8;

    /** Silent when the current gap is longer than FACTOR × the 95th percentile gap … */
    public const float FACTOR = 2.0;

    /** … but never before this many hours (nights and weekends are quiet) … */
    public const int FLOOR_HOURS = 12;

    /** … and always after this many (a channel that is usually quiet for a week is still dead after four days). */
    public const int CEILING_HOURS = 96;

    /**
     * @return list<array{key: string, label: string, state: string, last_at: ?string, silent_hours: ?float, limit_hours: ?float, events_30d: int}>
     */
    public function channels(): array
    {
        $rows = [];
        foreach ($this->sources() as $source) {
            try {
                $rows[] = $this->measure($source['key'], $source['label'], $source['times']());
            } catch (Throwable $error) {
                report($error);
            }
        }

        return $rows;
    }

    public function evaluate(OperationalAlertLifecycleService $lifecycle): int
    {
        $opened = 0;
        foreach ($this->channels() as $channel) {
            if ($channel['state'] !== self::SILENT) {
                $lifecycle->resolveIfActive(self::RULE, 'CHANNEL', $channel['key']);

                continue;
            }
            $lifecycle->observeCondition(
                ruleKey: self::RULE,
                ruleVersion: 1,
                ruleType: OperationalAlertRuleType::DatasetStale,
                family: OperationalSignalFamily::Credential,
                severity: OperationalAlertSeverity::Critical,
                scopeType: 'CHANNEL',
                scopeKey: $channel['key'],
                title: $channel['label'].': '.self::hoursText((float) $channel['silent_hours']).' hiç yeni mesaj gelmedi',
                summary: sprintf('Bu kanala normalde en geç %s içinde yeni mesaj gelir (son 30 günde %d mesaj). Bağlantı "bağlı" görünse bile mesaj akışı kopmuş olabilir; telefonda bağlı cihazları ve Bağlantı ekranını kontrol edin.',
                    self::hoursText((float) $channel['limit_hours']), $channel['events_30d']),
                observed: ['last_at' => $channel['last_at'], 'silent_hours' => $channel['silent_hours'], 'limit_hours' => $channel['limit_hours'], 'events_30d' => $channel['events_30d']],
            );
            $opened++;
        }

        return $opened;
    }

    /**
     * @param  list<CarbonImmutable>  $times  arrival times inside the window, any order
     * @return array{key: string, label: string, state: string, last_at: ?string, silent_hours: ?float, limit_hours: ?float, events_30d: int}
     */
    public function measure(string $key, string $label, array $times, ?CarbonImmutable $now = null): array
    {
        $now ??= CarbonImmutable::now();
        usort($times, fn (CarbonImmutable $a, CarbonImmutable $b): int => $a <=> $b);
        $last = $times === [] ? null : $times[count($times) - 1];
        $row = ['key' => $key, 'label' => $label, 'state' => self::UNKNOWN, 'last_at' => $last?->toIso8601String(),
            'silent_hours' => $last !== null ? round($last->diffInSeconds($now) / 3600, 1) : null, 'limit_hours' => null, 'events_30d' => count($times)];
        if (count($times) < self::MIN_EVENTS) {
            return $row;
        }
        $gaps = [];
        for ($i = 1, $n = count($times); $i < $n; $i++) {
            $gaps[] = $times[$i - 1]->diffInSeconds($times[$i]) / 3600;
        }
        sort($gaps);
        $p95 = $gaps[(int) floor(0.95 * (count($gaps) - 1))];
        $limit = round(min(self::CEILING_HOURS, max(self::FLOOR_HOURS, self::FACTOR * $p95)), 1);
        $row['limit_hours'] = $limit;
        $row['state'] = $row['silent_hours'] > $limit ? self::SILENT : self::OK;

        return $row;
    }

    public static function hoursText(float $hours): string
    {
        return $hours >= 48 ? (int) floor($hours / 24).' gündür' : (int) round($hours).' saattir';
    }

    /** @return list<array{key: string, label: string, times: callable(): list<CarbonImmutable>}> */
    private function sources(): array
    {
        $since = CarbonImmutable::now()->subDays(self::WINDOW_DAYS);
        $sources = [];
        if (SchemaState::hasTable('whatsapp_messages') && app(WhatsAppConnection::class)->integration() !== null) {
            $sources[] = ['key' => 'whatsapp', 'label' => 'WhatsApp', 'times' => fn (): array => $this->times(
                DB::table('whatsapp_messages')->where('direction', 'incoming')->where('is_history', false)->where('created_at', '>=', $since))];
        }
        if (SchemaState::hasTable('social_messages')) {
            $accounts = SocialAccount::query()->where('enabled', true)->whereIn('platform', [SocialAccount::INSTAGRAM, SocialAccount::FACEBOOK])->get(['id', 'platform', 'name', 'username']);
            foreach ($accounts as $account) {
                $sources[] = ['key' => 'social:'.$account->id,
                    'label' => ($account->platform === SocialAccount::INSTAGRAM ? 'Instagram' : 'Facebook').' '.($account->username ?: $account->name),
                    'times' => fn (): array => $this->times(DB::table('social_messages')
                        ->join('social_threads', 'social_threads.id', '=', 'social_messages.social_thread_id')
                        ->where('social_threads.social_account_id', $account->id)->where('social_messages.direction', 'in')
                        ->where('social_messages.created_at', '>=', $since), 'social_messages.created_at')];
            }
        }

        return $sources;
    }

    /** @return list<CarbonImmutable> */
    private function times($query, string $column = 'created_at'): array
    {
        return $query->orderBy($column)->limit(20000)->pluck($column)
            ->filter()->map(fn ($value): CarbonImmutable => CarbonImmutable::parse((string) $value))->values()->all();
    }
}
