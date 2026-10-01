<?php

namespace App\Services\Brand;

use App\Ai\Agents\BrandCareAgent;
use App\Jobs\Brand\RunBrandCareJob;
use App\Models\Brand;
use App\Models\BrandMemory;
use App\Models\Suggestion;
use App\Services\Ai\AiProviderRuntimeConfig;
use App\Services\Ai\AiRouteResolver;
use App\Services\SeoTasks\SeoText;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Throwable;

/**
 * Marka bakım ajanı (operator decision 2026-11-17): every week, active brands only. It reads the Marka dosyası, the
 * sections changed since its last review and its own previous tasks — never the raw rows behind them. Nothing changed
 * (and the last review is younger than FULL_REVIEW_DAYS): no AI call at all.
 *
 * Output: a short note (kept on the brand), at most MAX_TASKS tasks that go into the ONE work list (`suggestions`,
 * decision `brand.care`, target the brand) and at most 3 questions for the operator. A task the operator dismissed or
 * did is never reopened; an open task the agent no longer proposes is closed. Nothing is written outside MoxDOP.
 */
final class BrandCare
{
    public const string KIND = 'care';

    public const string DECISION = 'brand.care';

    public const int MAX_TASKS = 5;

    public const int MAX_QUESTIONS = 3;

    /** Gaps that make a review meaningless (BrandGaps keys). */
    public const array BLOCKING_GAPS = ['no_website', 'no_services'];

    /** Even with nothing changed, the agent looks again after this long. */
    public const int FULL_REVIEW_DAYS = 28;

    public function __construct(
        private readonly BrandDossier $dossier,
        private readonly AiRouteResolver $routes,
        private readonly AiProviderRuntimeConfig $runtime,
    ) {}

    /** Queue one review (the tab's "Şimdi incele" or the weekly schedule). */
    public static function queue(Brand $brand, bool $force = false): void
    {
        RunBrandCareJob::dispatch((int) $brand->id, $force);
    }

    /**
     * @return array{status: string, tasks?: int, changed?: list<string>, message?: string}
     */
    public function run(Brand $brand, bool $force = false): array
    {
        if (! $brand->isOperational()) {
            return ['status' => 'not_operational'];
        }
        // A review on a brand without a website or services cannot be right: it stops and says why (the operator's
        // "Şimdi incele" still runs it).
        if (! $force) {
            $blocking = collect(app(BrandGaps::class)->detect($brand))->whereIn('key', self::BLOCKING_GAPS)->pluck('title')->all();
            if ($blocking !== []) {
                $this->save($brand, ['blocked' => 'Çalışmadı: '.implode(', ', $blocking).'. Eksikler giderilince kendiliğinden devam eder.', 'checked_at' => now()->toIso8601String()]
                    + array_diff_key(self::stored($brand) ?? [], ['blocked' => true]));

                return ['status' => 'blocked', 'message' => implode(', ', $blocking)];
            }
        }
        $file = $this->dossier->build($brand);
        $previous = self::stored($brand);
        $changed = BrandDossier::changedSince($brand, (array) ($previous['seen'] ?? []));
        $reviewedAt = isset($previous['reviewed_at']) ? Carbon::parse($previous['reviewed_at']) : null;
        if (! $force && $changed === [] && $reviewedAt !== null && $reviewedAt->gt(now()->subDays(self::FULL_REVIEW_DAYS))) {
            $this->save($brand, ['checked_at' => now()->toIso8601String()] + ($previous ?? []));

            return ['status' => 'unchanged', 'changed' => []];
        }
        $route = $this->routes->resolve(BrandCareAgent::OPERATION);
        if ($route->isEmpty()) {
            return ['status' => 'no_ai', 'message' => 'Uygun AI sağlayıcısı yok ya da aylık AI bütçesi doldu.'];
        }

        $mine = $this->mine($brand);
        $data = [
            'dossier' => $file['markdown'],
            'changed' => $previous === null ? [] : $changed,
            'previous' => $previous === null ? null : [
                'summary' => (string) ($previous['summary'] ?? ''),
                'tasks' => $mine->map(fn (Suggestion $s): array => ['title' => (string) $s->title, 'status' => (string) $s->status])->values()->all(),
            ],
            'open_titles' => Suggestion::query()->where('brand_id', $brand->id)->whereIn('status', [Suggestion::OPEN, Suggestion::APPROVED, Suggestion::RECHECK])
                ->where('decision_key', '!=', self::DECISION)->orderBy('priority')->orderByDesc('id')->limit(30)->pluck('title')->all(),
        ];
        $this->runtime->prepare(array_keys($route->providerModels));
        $agent = new BrandCareAgent;
        $raw = (array) $agent->prompt("DATA_JSON\n".json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
            provider: $route->providerModels, timeout: 180)->toArray();

        $tasks = $this->validTasks($raw, $data['open_titles'], $mine);
        $this->syncTasks($brand, $tasks, $mine, $agent->promptVersionId());
        // The agent's own tasks change "Açık işler": the file is rebuilt so they do not count as news next week.
        $file = $this->dossier->build($brand);
        $this->save($brand, [
            'seen' => array_map(fn (array $s): string => $s['hash'], $file['sections']),
            'reviewed_at' => now()->toIso8601String(),
            'checked_at' => now()->toIso8601String(),
            'summary' => mb_substr(trim((string) ($raw['summary'] ?? '')), 0, 600),
            'questions' => collect((array) ($raw['questions'] ?? []))->filter(fn ($q): bool => is_string($q) && trim($q) !== '')
                ->map(fn (string $q): string => mb_substr(trim($q), 0, 200))->take(self::MAX_QUESTIONS)->values()->all(),
            'changed' => $changed,
            'blocked' => null,
        ]);

        return ['status' => 'reviewed', 'tasks' => count($tasks), 'changed' => $changed];
    }

    /**
     * Runs the review and keeps a failure on the brand instead of throwing (the job and the tab read it).
     *
     * @return array{status: string, tasks?: int, changed?: list<string>, message?: string}
     */
    public function runSafely(int $brandId, bool $force = false): array
    {
        $brand = Brand::query()->find($brandId);
        if ($brand === null) {
            return ['status' => 'missing'];
        }
        try {
            return $this->run($brand, $force);
        } catch (Throwable $exception) {
            report($exception);
            $this->save($brand, ['failed_at' => now()->toIso8601String(), 'error' => mb_substr($exception->getMessage(), 0, 300)] + (self::stored($brand) ?? []));

            return ['status' => 'failed', 'message' => $exception->getMessage()];
        }
    }

    /**
     * @return array{seen?: array<string, string>, reviewed_at?: string, checked_at?: string, summary?: string, questions?: list<string>, changed?: list<string>, failed_at?: string, error?: string}|null
     */
    public static function stored(Brand $brand): ?array
    {
        $data = BrandMemory::query()->where('brand_id', $brand->id)->where('kind', self::KIND)->value('data');
        $data = is_string($data) ? json_decode($data, true) : $data;

        return is_array($data) ? $data : null;
    }

    /** @return Collection<int, Suggestion> the agent's tasks of this brand (any status), newest first */
    public function mine(Brand $brand): Collection
    {
        return Suggestion::query()->where('brand_id', $brand->id)->where('decision_key', self::DECISION)->orderByDesc('last_seen_at')->orderByDesc('id')->limit(20)->get();
    }

    /**
     * @param  array<string, mixed>  $raw
     * @param  list<string>  $openTitles
     * @param  Collection<int, Suggestion>  $mine
     * @return list<array{title: string, why: string, channel: string, priority: int}>
     */
    private function validTasks(array $raw, array $openTitles, Collection $mine): array
    {
        $blocked = collect($openTitles)->map(fn ($t): string => SeoText::fold((string) $t))
            ->merge($mine->whereIn('status', [Suggestion::DISMISSED, Suggestion::APPLIED])->map(fn (Suggestion $s): string => SeoText::fold((string) $s->title)))
            ->flip();
        $out = [];
        foreach ((array) ($raw['tasks'] ?? []) as $task) {
            $title = is_array($task) ? trim((string) ($task['title'] ?? '')) : '';
            $channel = is_array($task) ? (string) ($task['channel'] ?? '') : '';
            $key = SeoText::fold($title);
            if ($title === '' || ! in_array($channel, Suggestion::CHANNELS, true) || isset($blocked[$key]) || isset($out[$key])) {
                continue;
            }
            $out[$key] = [
                'title' => mb_substr($title, 0, 160),
                'why' => mb_substr(trim((string) ($task['why'] ?? '')), 0, 240),
                'channel' => $channel,
                'priority' => max(1, min(3, (int) ($task['priority'] ?? 2))),
            ];
            if (count($out) >= self::MAX_TASKS) {
                break;
            }
        }

        return array_values($out);
    }

    /**
     * @param  list<array{title: string, why: string, channel: string, priority: int}>  $tasks
     * @param  Collection<int, Suggestion>  $mine
     */
    private function syncTasks(Brand $brand, array $tasks, Collection $mine, ?int $promptVersionId): void
    {
        $kept = [];
        foreach ($tasks as $task) {
            $fingerprint = hash('sha256', implode('|', [$brand->id, self::DECISION, SeoText::fold($task['title'])]));
            $suggestion = Suggestion::query()->where('brand_id', $brand->id)->where('fingerprint', $fingerprint)->first() ?? new Suggestion;
            $suggestion->forceFill([
                'brand_id' => $brand->id, 'channel' => $task['channel'], 'decision_key' => self::DECISION, 'fingerprint' => $fingerprint,
                'material_hash' => hash('sha256', $task['title'].'|'.$task['why']), 'title' => $task['title'], 'reason' => $task['why'] !== '' ? $task['why'] : $task['title'],
                'priority' => $task['priority'], 'evidence' => [['kind' => 'care', 'value' => $task['why'], 'source' => 'Marka bakım ajanı']],
                'action_type' => 'care_task', 'target_type' => 'brand', 'target_id' => (int) $brand->id, 'prompt_version_id' => $promptVersionId,
                'status' => in_array($suggestion->status, [Suggestion::APPROVED, Suggestion::SNOOZED], true) ? $suggestion->status : Suggestion::OPEN,
                'first_seen_at' => $suggestion->first_seen_at ?? now(), 'last_seen_at' => now(), 'action' => (array) $suggestion->action,
            ])->save();
            $kept[] = (int) $suggestion->id;
        }
        Suggestion::query()->whereIn('id', $mine->where('status', Suggestion::OPEN)->pluck('id')->diff($kept)->values())
            ->update(['status' => Suggestion::DISMISSED, 'resolved_at' => now(), 'operator_note' => 'Bakım ajanı artık önermiyor.']);
    }

    /** @param  array<string, mixed>  $data */
    private function save(Brand $brand, array $data): void
    {
        $row = BrandMemory::query()->where('brand_id', $brand->id)->where('kind', self::KIND)->first()
            ?? new BrandMemory(['brand_id' => $brand->id, 'kind' => self::KIND, 'ref_type' => 'brand', 'ref_id' => $brand->id]);
        $row->forceFill(['summary' => $data['summary'] ?? null, 'data' => $data])->save();
    }

    /** The job died (timeout, worker lost): the tab shows the failure instead of "running". */
    public static function markFailed(int $brandId, string $message): void
    {
        $brand = Brand::query()->find($brandId);
        if ($brand !== null) {
            app(self::class)->save($brand, ['failed_at' => now()->toIso8601String(), 'error' => mb_substr($message, 0, 300)] + (self::stored($brand) ?? []));
        }
    }
}
