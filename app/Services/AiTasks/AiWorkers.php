<?php

namespace App\Services\AiTasks;

use App\Models\AgencySetting;
use App\Models\AiTask;
use App\Models\McpToken;
use App\Services\Ai\AiProviderRuntimeConfig;
use App\Services\Mcp\McpKeys;
use App\Services\Operator\AgencySettingService;
use App\Support\SchemaState;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * AI sağlayıcıları (yakup, 2026-10-27): who drains the AI queue. Four workers — Claude MCP, ChatGPT MCP (subscriptions
 * that come with their own routines), Claude API and ChatGPT API (run by the server, ApiQueueWorker) — in the order
 * the operator sets on Ayarlar › AI: the first one that is alive works, the others stand by.
 *
 * Subscription quotas cannot be read from outside, so an MCP worker is judged by what it does: it is "gelmiyor"
 * (quota used up or disconnected) when work has waited more than 90 minutes and it has not asked for work in that time.
 * A backup that asks while an earlier worker is alive is told "sıra sende değil" and gets no task, so its round ends at
 * once. A task a worker took stays its own for two hours (a quota that runs out mid-round leaves it half done); after
 * that anyone may take it, new work first. Every answer records who gave it (ai_tasks.done_by).
 */
final class AiWorkers
{
    public const string CLAUDE_MCP = 'claude_mcp';

    public const string CHATGPT_MCP = 'chatgpt_mcp';

    public const string CLAUDE_API = 'claude_api';

    public const string OPENAI_API = 'openai_api';

    public const array LABELS = [
        self::CLAUDE_MCP => 'Claude MCP',
        self::CHATGPT_MCP => 'ChatGPT MCP',
        self::CLAUDE_API => 'Claude API',
        self::OPENAI_API => 'ChatGPT API',
    ];

    public const array MCP = [self::CLAUDE_MCP, self::CHATGPT_MCP];

    /** API worker → provider key of the pricing table and the integration. */
    public const array API_PROVIDERS = [self::CLAUDE_API => 'anthropic', self::OPENAI_API => 'openai'];

    public const array DEFAULT_MODELS = [self::CLAUDE_API => 'claude-sonnet-5-5', self::OPENAI_API => 'gpt-5.4-mini'];

    /** Without routine times: an MCP worker that has not asked for work this long while work waits is taken as gone. */
    public const int SILENT_MINUTES = 90;

    /**
     * Routine times (Istanbul) each MCP worker comes at; one that did not come to its last round is taken as gone
     * (quota used up, routine stopped) until it comes again. yakup 2026-10-27: Claude :27, ChatGPT 30 minutes later.
     */
    public const array DEFAULT_ROUNDS = [
        self::CLAUDE_MCP => ['08:27', '12:27', '16:27', '21:27'],
        self::CHATGPT_MCP => ['08:57', '12:57', '16:57', '21:57'],
    ];

    /** A round counts as missed this long after its time (a routine may start a few minutes late). */
    public const int ROUND_GRACE_MINUTES = 20;

    /** A taken task stays with its worker this long before a backup may take it. */
    public const int RESERVE_MINUTES = 120;

    /** "Yedeğe geç" pauses the working one this long. */
    public const int PAUSE_HOURS = 24;

    public const float DEFAULT_USD_TRY = 42.0;

    private const string SEEN = 'ai-worker-seen:';

    private const string ERROR = 'ai-worker-error:';

    public static function label(string $doneBy): string
    {
        [$worker, $model] = array_pad(explode(':', $doneBy, 2), 2, null);

        return (self::LABELS[$worker] ?? $worker).($model !== null && $model !== '' ? ' · '.$model : '');
    }

    public static function isMcp(string $worker): bool
    {
        return in_array($worker, self::MCP, true);
    }

    /**
     * @return array{order: list<string>, paused: array<string, string>, api: array<string, array{enabled: bool, model: string, daily_try: float, monthly_try: float}>, usd_try: float, rounds: array<string, list<string>>}
     */
    public function settings(): array
    {
        $stored = $this->stored();
        $order = array_values(array_filter((array) ($stored['order'] ?? []), fn ($key): bool => is_string($key) && isset(self::LABELS[$key])));
        foreach (array_keys(self::LABELS) as $key) {
            if (! in_array($key, $order, true)) {
                $order[] = $key;
            }
        }
        $api = [];
        foreach (self::API_PROVIDERS as $key => $provider) {
            $row = (array) ($stored['api'][$key] ?? []);
            $api[$key] = [
                'enabled' => (bool) ($row['enabled'] ?? false),
                'model' => is_string($row['model'] ?? null) && $row['model'] !== '' ? $row['model'] : self::DEFAULT_MODELS[$key],
                'daily_try' => max(0.0, (float) ($row['daily_try'] ?? 0)),
                'monthly_try' => max(0.0, (float) ($row['monthly_try'] ?? 0)),
            ];
        }

        return [
            'order' => $order,
            'paused' => array_filter((array) ($stored['paused'] ?? []), fn ($until): bool => is_string($until) && Carbon::parse($until)->isFuture()),
            'api' => $api,
            'usd_try' => (float) ($stored['usd_try'] ?? 0) > 0 ? (float) $stored['usd_try'] : self::DEFAULT_USD_TRY,
            'rounds' => array_map(fn (string $worker): array => is_array($stored['rounds'][$worker] ?? null) ? self::cleanTimes($stored['rounds'][$worker]) : self::DEFAULT_ROUNDS[$worker],
                array_combine(self::MCP, self::MCP)),
        ];
    }

    /** @return list<string> */
    public function order(): array
    {
        return $this->settings()['order'];
    }

    /** @param  list<string>  $order */
    public function setOrder(array $order): void
    {
        $this->update(['order' => array_values(array_unique(array_filter($order, fn ($key): bool => is_string($key) && isset(self::LABELS[$key]))))]);
    }

    /** Moves a worker one place up (-1) or down (+1), or to a given place. */
    public function move(string $worker, int $to): void
    {
        $order = $this->order();
        $from = array_search($worker, $order, true);
        if ($from === false) {
            return;
        }
        $to = max(0, min(count($order) - 1, $to));
        array_splice($order, $from, 1);
        array_splice($order, $to, 0, [$worker]);
        $this->setOrder($order);
    }

    public function pause(string $worker, int $hours = self::PAUSE_HOURS): void
    {
        $paused = $this->settings()['paused'];
        $paused[$worker] = now()->addHours($hours)->toIso8601String();
        $this->update(['paused' => $paused]);
    }

    public function resume(string $worker): void
    {
        $paused = $this->settings()['paused'];
        unset($paused[$worker]);
        $this->update(['paused' => $paused]);
        Cache::forget(self::ERROR.$worker);
    }

    public function saveApi(string $worker, bool $enabled, string $model, float $dailyTry, float $monthlyTry): void
    {
        if (! isset(self::API_PROVIDERS[$worker])) {
            return;
        }
        $api = $this->settings()['api'];
        $api[$worker] = ['enabled' => $enabled, 'model' => $model, 'daily_try' => max(0.0, $dailyTry), 'monthly_try' => max(0.0, $monthlyTry)];
        $this->update(['api' => $api]);
        if ($enabled) {
            Cache::forget(self::ERROR.$worker);
        }
    }

    /** @param  list<string>  $times  HH:MM in Istanbul time; empty = judge by 90 minutes of silence instead */
    public function setRounds(string $worker, array $times): void
    {
        if (! self::isMcp($worker)) {
            return;
        }
        $rounds = $this->settings()['rounds'];
        $rounds[$worker] = self::cleanTimes($times);
        $this->update(['rounds' => $rounds]);
    }

    /** @return list<string> valid HH:MM times, sorted */
    public static function cleanTimes(array $times): array
    {
        $clean = [];
        foreach ($times as $time) {
            if (is_string($time) && preg_match('/^([01]?\d|2[0-3])[:.]([0-5]\d)$/', trim($time), $m)) {
                $clean[] = sprintf('%02d:%02d', (int) $m[1], (int) $m[2]);
            }
        }
        $clean = array_values(array_unique($clean));
        sort($clean);

        return $clean;
    }

    /** The worker's last routine round that should have happened by now (its time plus the grace), or null without times. */
    public function lastRound(string $worker): ?Carbon
    {
        $times = $this->settings()['rounds'][$worker] ?? [];
        $now = now('Europe/Istanbul');
        $last = null;
        foreach ([0, 1] as $daysBack) {
            foreach ($times as $time) {
                [$h, $m] = array_map('intval', explode(':', $time));
                $at = $now->copy()->subDays($daysBack)->setTime($h, $m);
                if ($at->copy()->addMinutes(self::ROUND_GRACE_MINUTES)->lte($now) && ($last === null || $at->gt($last))) {
                    $last = $at;
                }
            }
        }

        return $last;
    }

    /** The worker's next routine round, or null without times. */
    public function nextRound(string $worker): ?Carbon
    {
        $times = $this->settings()['rounds'][$worker] ?? [];
        $now = now('Europe/Istanbul');
        $next = null;
        foreach ([0, 1] as $daysAhead) {
            foreach ($times as $time) {
                [$h, $m] = array_map('intval', explode(':', $time));
                $at = $now->copy()->addDays($daysAhead)->setTime($h, $m);
                if ($at->gt($now) && ($next === null || $at->lt($next))) {
                    $next = $at;
                }
            }
        }

        return $next;
    }

    public function setUsdTry(float $rate): void
    {
        if ($rate > 0) {
            $this->update(['usd_try' => $rate]);
        }
    }

    /** @return list<string> models the API worker can be set to (the priced ones, so the TL limits work) */
    public static function models(string $worker): array
    {
        $provider = self::API_PROVIDERS[$worker] ?? null;
        $models = $provider !== null ? array_keys((array) config('moxdop-ai-pricing.models.'.$provider, [])) : [];

        return array_values(array_filter($models, fn (string $model): bool => $model !== '*' && ! preg_match('/-\d{8}$/', $model)));
    }

    /** The worker can be reached: an MCP key of it is on (Claude also through the old server key), or an API key is entered. */
    public function connected(string $worker): bool
    {
        if (self::isMcp($worker)) {
            if ($worker === self::CLAUDE_MCP && McpKeys::envKeyOn()) {
                return true;
            }

            return $this->tokens($worker)->isNotEmpty();
        }
        $provider = self::API_PROVIDERS[$worker] ?? null;
        if ($provider === null) {
            return false;
        }
        $runtime = app(AiProviderRuntimeConfig::class);

        return $provider === 'anthropic' ? $runtime->prepareAnthropic() : $runtime->prepareOpenAi();
    }

    /** @return Collection<int, McpToken> the worker's working write keys */
    public function tokens(string $worker): Collection
    {
        if (! SchemaState::hasColumn('mcp_tokens', 'provider')) {
            return collect();
        }
        try {
            return McpToken::query()->where('provider', $worker)->whereNull('revoked_at')->where('scope', McpToken::WRITE)->latest('id')->get();
        } catch (QueryException) {
            return collect();
        }
    }

    /** The worker asked for queue work (MCP) or ran a round (API) now. */
    public function markSeen(string $worker): void
    {
        Cache::forever(self::SEEN.$worker, now()->toIso8601String());
    }

    public function seenAt(string $worker): ?Carbon
    {
        $seen = Cache::get(self::SEEN.$worker);
        if (is_string($seen) && $seen !== '') {
            return Carbon::parse($seen);
        }

        return null;
    }

    /** When an MCP worker last asked for queue work; before the first such call, when its key was last used. */
    public function lastSeen(string $worker): ?Carbon
    {
        $seen = $this->seenAt($worker);
        if ($seen !== null || ! self::isMcp($worker)) {
            return $seen;
        }
        $used = $this->tokens($worker)->max('last_used_at');

        return $used !== null ? Carbon::parse($used) : null;
    }

    /** An API answered with an error that will not pass by itself soon (key, credit, limit): it stands down for a while. */
    public function apiFailed(string $worker, string $message, int $minutes = 60): void
    {
        Cache::put(self::ERROR.$worker, ['message' => Str::limit($message, 240), 'at' => now()->toIso8601String(),
            'until' => now()->addMinutes($minutes)->toIso8601String()], now()->addDays(3));
    }

    /** @return array{message: string, at: string, until: string}|null */
    public function lastError(string $worker): ?array
    {
        $error = Cache::get(self::ERROR.$worker);

        return is_array($error) ? $error : null;
    }

    /** Minutes the oldest untaken task has waited, null when nothing waits. */
    public function oldestWaitingMinutes(): ?int
    {
        $oldest = AiTask::query()->where('status', AiTask::PENDING)->min('created_at');

        return $oldest !== null ? (int) Carbon::parse((string) $oldest)->diffInMinutes(now()) : null;
    }

    /** Why the worker does not work now, null when it is alive. */
    public function downReason(string $worker): ?string
    {
        $settings = $this->settings();
        if (isset($settings['paused'][$worker])) {
            return 'Duraklatıldı ('.Carbon::parse($settings['paused'][$worker])->timezone('Europe/Istanbul')->format('d.m H:i').'\'e kadar)';
        }
        if (! $this->connected($worker)) {
            return 'Bağlı değil';
        }
        if (self::isMcp($worker)) {
            $seen = $this->lastSeen($worker);
            // With routine times: gone when it did not come to its last round (between rounds it is simply waiting
            // for the next one), and it stays gone until it comes again.
            $round = $this->lastRound($worker);
            if ($round !== null) {
                if ($seen === null || $seen->lt($round->copy()->subMinutes(10))) {
                    return 'Gelmiyor: '.$round->format('d.m H:i').' turuna gelmedi (kota bitmiş ya da rutin çalışmamış olabilir)';
                }

                return null;
            }
            $waiting = $this->oldestWaitingMinutes();
            if ($waiting !== null && $waiting >= self::SILENT_MINUTES && ($seen === null || $seen->lt(now()->subMinutes(self::SILENT_MINUTES)))) {
                return 'Gelmiyor: iş bekliyor ama '.self::SILENT_MINUTES.' dakikadır uğramadı (kota bitmiş ya da bağlantı kopmuş olabilir)';
            }

            return null;
        }
        $api = $settings['api'][$worker];
        if (! $api['enabled']) {
            return 'Kapalı';
        }
        $error = $this->lastError($worker);
        if ($error !== null && Carbon::parse($error['until'])->isFuture()) {
            return 'Hata verdi: '.$error['message'];
        }
        if ($api['daily_try'] > 0 && $this->spendTry($worker, now('Europe/Istanbul')->startOfDay()) >= $api['daily_try']) {
            return 'Günlük TL sınırı doldu';
        }
        if ($api['monthly_try'] > 0 && $this->spendTry($worker, now('Europe/Istanbul')->startOfMonth()) >= $api['monthly_try']) {
            return 'Aylık TL sınırı doldu';
        }

        return null;
    }

    public function alive(string $worker): bool
    {
        return $this->downReason($worker) === null;
    }

    /** The worker that works now: the first alive one in the order, null when none is. */
    public function active(): ?string
    {
        foreach ($this->order() as $worker) {
            if ($this->alive($worker)) {
                return $worker;
            }
        }

        return null;
    }

    /** Whether this worker may take work now: no worker before it in the order is alive. */
    public function mayWork(string $worker): bool
    {
        foreach ($this->order() as $key) {
            if ($key === $worker) {
                return true;
            }
            if ($this->alive($key)) {
                return false;
            }
        }

        return false;
    }

    /** What a backup that asks out of turn is told. */
    public function standDownMessage(string $worker): string
    {
        $active = $this->active();

        return 'Sıra sende değil: şu an '.($active !== null ? self::LABELS[$active] : 'senden önceki sağlayıcı').' çalışıyor (Ayarlar › AI sırası). '
            .'Bu turda iş yok; hiçbir şey yapmadan turu bitir.';
    }

    /**
     * The tasks this worker may take: untaken ones, its own taken ones, and others' taken ones after two hours (those
     * last, so a backup does new work first).
     *
     * @param  Builder<AiTask>  $query
     * @return Builder<AiTask>
     */
    public static function servable(Builder $query, string $worker): Builder
    {
        $cutoff = now()->subMinutes(self::RESERVE_MINUTES);

        return $query->where(fn (Builder $q) => $q->where('status', AiTask::PENDING)
            ->orWhere(fn (Builder $claimed) => $claimed->where('status', AiTask::CLAIMED)
                ->where(fn (Builder $mine) => $mine->whereRaw('coalesce(claimed_by, ?) = ?', [self::CLAUDE_MCP, $worker])
                    ->orWhere('claimed_at', '<', $cutoff)->orWhereNull('claimed_at'))))
            ->orderByRaw('case when status = ? or coalesce(claimed_by, ?) = ? then 0 else 1 end', [AiTask::PENDING, self::CLAUDE_MCP, $worker]);
    }

    /** What the API worker spent since then, in TL. */
    public function spendTry(string $worker, \DateTimeInterface $since): float
    {
        if (! SchemaState::hasColumn('ai_tasks', 'cost_usd')) {
            return 0.0;
        }
        $usd = (float) AiTask::query()->where('done_by', 'like', $worker.':%')->where('completed_at', '>=', $since)->sum('cost_usd');

        return round($usd * $this->settings()['usd_try'], 2);
    }

    /**
     * One row of the Ayarlar › AI list.
     *
     * @return array<string, mixed>
     */
    public function status(string $worker): array
    {
        $settings = $this->settings();
        $reason = $this->downReason($worker);
        $active = $this->active();
        $doneQuery = fn (\DateTimeInterface $since) => AiTask::query()->where(fn ($q) => $q->where('done_by', $worker)->orWhere('done_by', 'like', $worker.':%'))
            ->where('completed_at', '>=', $since);
        $hasColumns = SchemaState::hasColumn('ai_tasks', 'done_by');
        $lastDone = $hasColumns ? $doneQuery(now()->subYears(5))->max('completed_at') : null;

        return [
            'key' => $worker,
            'label' => self::LABELS[$worker],
            'mcp' => self::isMcp($worker),
            'position' => array_search($worker, $settings['order'], true) + 1,
            'connected' => $this->connected($worker),
            'state' => match (true) {
                $worker === $active => 'active',
                $reason === null => 'standby',
                isset($settings['paused'][$worker]) => 'paused',
                default => 'down',
            },
            'reason' => $reason,
            'seen_at' => self::isMcp($worker) ? $this->lastSeen($worker) : $this->seenAt($worker),
            'last_done_at' => $lastDone !== null ? Carbon::parse((string) $lastDone) : null,
            'done_24h' => $hasColumns ? $doneQuery(now()->subDay())->count() : 0,
            'done_7d' => $hasColumns ? $doneQuery(now()->subDays(7))->count() : 0,
            'error' => $this->lastError($worker),
            'paused_until' => $settings['paused'][$worker] ?? null,
            'rounds' => $settings['rounds'][$worker] ?? [],
            'next_round' => self::isMcp($worker) ? $this->nextRound($worker) : null,
            'api' => $settings['api'][$worker] ?? null,
            'spend_today' => self::isMcp($worker) ? null : $this->spendTry($worker, now('Europe/Istanbul')->startOfDay()),
            'spend_month' => self::isMcp($worker) ? null : $this->spendTry($worker, now('Europe/Istanbul')->startOfMonth()),
            'tokens' => self::isMcp($worker) ? $this->tokens($worker) : collect(),
        ];
    }

    /** @return array<string, mixed> */
    private function stored(): array
    {
        if (! SchemaState::hasColumn('agency_settings', 'ai_workers')) {
            return [];
        }
        try {
            return (array) (AgencySetting::query()->first()?->ai_workers ?? []);
        } catch (QueryException) {
            return [];
        }
    }

    /** @param  array<string, mixed>  $changes */
    private function update(array $changes): void
    {
        $setting = AgencySetting::query()->first() ?? app(AgencySettingService::class)->current();
        $setting->forceFill(['ai_workers' => array_replace($this->stored(), $changes)])->save();
    }
}
