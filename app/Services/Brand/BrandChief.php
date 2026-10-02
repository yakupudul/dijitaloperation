<?php

namespace App\Services\Brand;

use App\Ai\Agents\BrandChiefAgent;
use App\Models\Brand;
use App\Models\ChiefPlan;
use App\Models\Suggestion;
use App\Services\Ai\AiProviderRuntimeConfig;
use App\Services\Ai\AiRouteResolver;
use App\Services\Observability\ErrorTriage;
use App\Services\Queries\QueryNotifier;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * Şef (operator decision 2026-11-17): every Monday one plan for the operator's week across the active brands. First it
 * runs the Şef denetimi (BrandAudit, rules only: errors in what the AI did for each brand). It reads
 * only what the care agents already wrote (note, open tasks), the brands' goals and the open work counts — one small
 * call, never the raw data. The plan is kept per week and sent as one notice.
 */
final class BrandChief
{
    public const int MAX_LINES = 10;

    public const int MAX_PER_BRAND = 3;

    public function __construct(
        private readonly AiRouteResolver $routes,
        private readonly AiProviderRuntimeConfig $runtime,
        private readonly ErrorTriage $triage,
        private readonly QueryNotifier $notifier,
        private readonly BrandAudit $audit,
    ) {}

    public static function weekStart(): Carbon
    {
        return now('Europe/Istanbul')->startOfWeek(Carbon::MONDAY);
    }

    public static function current(): ?ChiefPlan
    {
        return ChiefPlan::query()->orderByDesc('week_start')->first();
    }

    /** @return array{status: string, lines?: int, message?: string} */
    public function run(bool $notify = true): array
    {
        $week = self::weekStart()->toDateString();
        $brands = Brand::query()->operational()->orderBy('name')->get();
        if ($brands->isEmpty()) {
            return ['status' => 'no_brands'];
        }
        $route = $this->routes->resolve(BrandChiefAgent::OPERATION);
        if ($route->isEmpty()) {
            return ['status' => 'no_ai', 'message' => 'Uygun AI sağlayıcısı yok ya da aylık AI bütçesi doldu.'];
        }
        try {
            // Şef denetimi first (rules, no AI): errors in what the AI did reach the plan.
            foreach ($brands as $brand) {
                $this->audit->sync($brand);
            }
            $errors = $this->triage->counts();
            $data = ['brands' => $brands->map(fn (Brand $brand): array => $this->brandInput($brand))->values()->all(),
                'errors_waiting' => (int) ($errors[ErrorTriage::YOU] ?? 0) + (int) ($errors[ErrorTriage::CODE] ?? 0)];
            $this->runtime->prepare(array_keys($route->providerModels));
            $raw = (array) (new BrandChiefAgent)->prompt("DATA_JSON\n".json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
                provider: $route->providerModels, timeout: 180)->toArray();
            $plan = $this->validPlan($raw, $brands->pluck('name', 'id')->all());
            ChiefPlan::query()->updateOrCreate(['week_start' => $week], [
                'headline' => mb_substr(trim((string) ($raw['headline'] ?? '')), 0, 300), 'plan' => $plan, 'status' => 'ready', 'error' => null,
            ]);
        } catch (Throwable $exception) {
            report($exception);
            ChiefPlan::query()->updateOrCreate(['week_start' => $week], ['status' => 'failed', 'error' => mb_substr($exception->getMessage(), 0, 300)]);

            return ['status' => 'failed', 'message' => $exception->getMessage()];
        }
        if ($notify && $plan !== []) {
            $this->notifier->send(null, 'Şef: bu haftanın planı hazır ('.count($plan).' iş)', route('operator.dashboard', [], false));
        }

        return ['status' => 'ready', 'lines' => count($plan)];
    }

    /** @return array<string, mixed> */
    private function brandInput(Brand $brand): array
    {
        $care = BrandCare::stored($brand) ?? [];
        $notes = BrandDossier::notes($brand);

        return [
            'id' => (int) $brand->id,
            'name' => (string) $brand->name,
            'goals' => $notes['goals'],
            'care_summary' => (string) ($care['summary'] ?? ''),
            'care_reviewed_at' => isset($care['reviewed_at']) ? substr((string) $care['reviewed_at'], 0, 10) : null,
            'care_tasks' => Suggestion::query()->where('brand_id', $brand->id)->where('decision_key', BrandCare::DECISION)->actionable()
                ->orderBy('priority')->limit(BrandCare::MAX_TASKS)->get(['title', 'priority'])->map(fn (Suggestion $s): array => ['title' => (string) $s->title, 'priority' => (int) $s->priority])->all(),
            'audit_errors' => Suggestion::query()->where('brand_id', $brand->id)->where('decision_key', BrandAudit::DECISION)->actionable()
                ->orderBy('id')->pluck('title')->map(fn ($t): string => (string) $t)->all(),
            'open_by_channel' => Suggestion::query()->where('brand_id', $brand->id)->actionable()->selectRaw('channel, count(*) as n')->groupBy('channel')->pluck('n', 'channel')
                ->map(fn ($n): int => (int) $n)->all(),
        ];
    }

    /**
     * @param  array<string, mixed>  $raw
     * @param  array<int, string>  $names  brand id => name
     * @return list<array{brand_id: int, brand: string, task: string, why: string}>
     */
    private function validPlan(array $raw, array $names): array
    {
        $out = [];
        $perBrand = [];
        foreach ((array) ($raw['plan'] ?? []) as $line) {
            $brandId = is_array($line) ? (int) ($line['brand_id'] ?? -1) : -1;
            $task = is_array($line) ? trim((string) ($line['task'] ?? '')) : '';
            if ($task === '' || ($brandId !== 0 && ! isset($names[$brandId])) || ($perBrand[$brandId] ?? 0) >= self::MAX_PER_BRAND) {
                continue;
            }
            $perBrand[$brandId] = ($perBrand[$brandId] ?? 0) + 1;
            $out[] = ['brand_id' => $brandId, 'brand' => $brandId === 0 ? 'Hata merkezi' : $names[$brandId], 'task' => mb_substr($task, 0, 160),
                'why' => mb_substr(trim((string) ($line['why'] ?? '')), 0, 240)];
            if (count($out) >= self::MAX_LINES) {
                break;
            }
        }

        return $out;
    }
}
