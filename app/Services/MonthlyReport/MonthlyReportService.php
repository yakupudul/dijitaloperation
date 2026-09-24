<?php

namespace App\Services\MonthlyReport;

use App\Ai\Agents\MonthlyReportCommentaryAgent;
use App\Jobs\WriteMonthlyReportCommentaryJob;
use App\Models\Brand;
use App\Models\MonthlyReport;
use App\Models\User;
use App\Services\Ai\AiProviderRuntimeConfig;
use App\Services\Ai\AiRouteResolver;
use App\Support\Ai\AiRouteKeys;
use Illuminate\Support\Facades\URL;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Monthly report lifecycle (Faz 9): build / refresh the frozen numbers, AI commentary on click (queued), the
 * operator's edits, publish and a signed client link. Nothing is sent automatically.
 */
final class MonthlyReportService
{
    public function __construct(
        private readonly MonthlyReportBuilder $builder,
        private readonly AiRouteResolver $routes,
        private readonly AiProviderRuntimeConfig $runtime,
    ) {}

    /** Build (or refresh) the month's numbers; the operator note and commentary are kept. */
    public function prepare(Brand $brand, string $month, ?User $actor = null): MonthlyReport
    {
        if (preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month) !== 1 || $month > now()->format('Y-m')) {
            throw ValidationException::withMessages(['month' => 'Geçerli bir ay seçin.']);
        }
        $report = MonthlyReport::query()->firstOrNew(['brand_id' => $brand->id, 'month' => $month]);
        $report->fill(['payload' => $this->builder->build($brand, $month)]);
        $report->created_by ??= $actor?->id;
        $report->save();

        return $report;
    }

    public function requestCommentary(MonthlyReport $report): void
    {
        if ($report->commentary_status === 'queued') {
            return;
        }
        $report->forceFill(['commentary_status' => 'queued'])->save();
        WriteMonthlyReportCommentaryJob::dispatch((int) $report->id);
    }

    /** One AI call from the frozen payload. */
    public function writeCommentary(MonthlyReport $report): MonthlyReport
    {
        try {
            $route = $this->routes->resolve(AiRouteKeys::MONTHLY_REPORT_COMMENTARY);
            if ($route->isEmpty()) {
                return $this->failCommentary($report, 'Uygun AI sağlayıcısı yok ya da aylık AI bütçesi doldu (Ayarlar → AI).');
            }
            $this->runtime->prepare(array_keys($route->providerModels));
            $response = (new MonthlyReportCommentaryAgent)->prompt(
                "REPORT_JSON\n".json_encode($this->context($report), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
                provider: $route->providerModels,
                timeout: 120,
            );
            $commentary = self::clean((array) $response->toArray());
        } catch (Throwable $exception) {
            return $this->failCommentary($report, 'Yorum yazılamadı: '.mb_substr($exception->getMessage(), 0, 200));
        }
        if ($commentary['summary'] === '') {
            return $this->failCommentary($report, 'AI boş yorum döndürdü; tekrar dene.');
        }
        $report->forceFill(['commentary' => $commentary + [
            'source' => 'llm', 'prompt_version' => MonthlyReportCommentaryAgent::PROMPT_VERSION,
            'provider' => $route->primaryProvider(), 'model' => $route->primaryModel(), 'created_at' => now()->toIso8601String(),
        ], 'commentary_status' => 'ready'])->save();

        return $report;
    }

    /** Operator edits of the commentary (summary + bullet lists, one per line). */
    public function saveCommentary(MonthlyReport $report, string $summary, string $wins, string $watch, string $next, ?string $note): void
    {
        $lines = static fn (string $text): array => array_values(array_filter(array_map('trim', preg_split('/\r?\n/', $text) ?: []), static fn (string $l): bool => $l !== ''));
        $previous = (array) ($report->commentary ?? []);
        $report->forceFill([
            'commentary' => self::clean(['summary' => $summary, 'wins' => $lines($wins), 'watch' => $lines($watch), 'next_month' => $lines($next)]) + array_intersect_key($previous, array_flip(['provider', 'model', 'prompt_version'])) + ['source' => 'operator', 'edited_at' => now()->toIso8601String()],
            'commentary_status' => 'ready',
            'operator_note' => filled($note) ? mb_substr(trim((string) $note), 0, 3000) : null,
        ])->save();
    }

    public function publish(MonthlyReport $report): void
    {
        $report->forceFill(['status' => 'published', 'published_at' => now()])->save();
    }

    public function clientUrl(MonthlyReport $report): string
    {
        return URL::temporarySignedRoute('monthly-report.client', now()->addDays((int) config('moxdop-reports.client_link_days', 60)), ['report' => $report->id]);
    }

    /** @return array{summary: string, wins: list<string>, watch: list<string>, next_month: list<string>} */
    public static function clean(array $raw): array
    {
        $text = static fn (mixed $v, int $max): string => mb_substr(trim(preg_replace('/\s+/u', ' ', is_string($v) ? strip_tags($v) : '') ?? ''), 0, $max);
        $list = static fn (mixed $v, int $count): array => array_values(array_slice(array_filter(array_map(static fn ($l): string => $text($l, 300), (array) $v), static fn (string $l): bool => $l !== ''), 0, $count));

        return ['summary' => $text($raw['summary'] ?? '', 1500), 'wins' => $list($raw['wins'] ?? [], 6), 'watch' => $list($raw['watch'] ?? [], 5), 'next_month' => $list($raw['next_month'] ?? [], 6)];
    }

    /** Compact payload for the prompt (no daily series). */
    private function context(MonthlyReport $report): array
    {
        $payload = (array) $report->payload;
        foreach ((array) ($payload['channels'] ?? []) as $key => $channel) {
            unset($payload['channels'][$key]['series']);
            if (! ($channel['available'] ?? false)) {
                unset($payload['channels'][$key]);
            }
        }
        unset($payload['built_at']);

        return $payload;
    }

    private function failCommentary(MonthlyReport $report, string $message): MonthlyReport
    {
        $report->forceFill(['commentary_status' => 'failed', 'commentary' => array_merge((array) ($report->commentary ?? []), ['error' => $message])])->save();

        return $report;
    }
}
