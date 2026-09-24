<?php

namespace App\Services\Intel;

use App\Ai\Agents\AiVisibilityProbeAgent;
use App\Jobs\RunAiVisibilityCheckJob;
use App\Models\Brand;
use App\Models\BrandOffering;
use App\Models\BrandServiceArea;
use App\Models\Intel\BrandIntelSetting;
use App\Models\SearchDemandCompetitor;
use App\Models\User;
use App\Services\Ai\AiProviderRuntimeConfig;
use App\Services\Ai\AiRouteResolver;
use App\Services\SeoTasks\SeoText;
use App\Support\Ai\AiRouteKeys;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * AI görünürlüğü (Faz 10c): customer-style questions built from the brand's services and areas are put to the
 * configured AI assistant (on click, queued) without naming the brand; each answer is checked for the brand and
 * its competitors. Shows what the model recommends from its own knowledge — not a live web search, and not a
 * measure of every assistant people use.
 */
final class AiVisibilityService
{
    public function __construct(
        private readonly AiRouteResolver $routes,
        private readonly AiProviderRuntimeConfig $runtime,
        private readonly BrandGbpIdentity $identity,
    ) {}

    /** Saved questions, else suggestions from priority services × service areas. @return list<string> */
    public function prompts(Brand $brand): array
    {
        $saved = array_values(array_filter(array_map('trim', (array) BrandIntelSetting::for($brand)->ai_visibility_prompts)));
        if ($saved !== []) {
            return array_slice($saved, 0, (int) config('moxdop-intel.ai_visibility.max_prompts', 6));
        }
        $services = BrandOffering::query()->where('brand_id', $brand->id)->where('status', 'active')->orderByDesc('is_priority')->orderBy('priority_rank')
            ->with('primaryName')->limit(3)->get()->map(fn (BrandOffering $o): ?string => $o->primaryName?->raw_label)->filter()->values();
        $areas = BrandServiceArea::query()->where('brand_id', $brand->id)->where('status', 'active')->orderBy('priority_rank')->limit(2)->get()
            ->map(fn (BrandServiceArea $a): string => (string) ($a->district_name ?: $a->city_name))->filter()->values();
        $prompts = [];
        foreach ($services as $service) {
            foreach ($areas->isEmpty() ? collect([null]) : $areas as $area) {
                $lower = mb_strtolower(str_replace(['İ', 'I'], ['i', 'ı'], $service));
                $prompts[] = $area !== null ? sprintf('%s bölgesinde %s için hangi yeri önerirsin?', $area, $lower) : sprintf('%s için hangi yeri önerirsin?', $service);
            }
        }

        return array_slice($prompts, 0, (int) config('moxdop-intel.ai_visibility.max_prompts', 6));
    }

    /** Queue one check batch; returns the batch id. */
    public function run(Brand $brand, array $prompts, ?User $actor = null): string
    {
        $prompts = array_slice(array_values(array_unique(array_filter(array_map(static fn ($p): string => mb_substr(trim((string) $p), 0, 300), $prompts)))), 0, (int) config('moxdop-intel.ai_visibility.max_prompts', 6));
        if ($prompts === []) {
            throw ValidationException::withMessages(['ai' => 'Soru yok: markaya hizmet ve bölge ekleyin ya da soruları yazın.']);
        }
        if ($this->routes->resolve(AiRouteKeys::AI_VISIBILITY_PROBE)->isEmpty()) {
            throw ValidationException::withMessages(['ai' => 'Uygun AI sağlayıcısı yok ya da aylık AI bütçesi doldu (Ayarlar → AI).']);
        }
        $batch = (string) Str::uuid();
        foreach ($prompts as $prompt) {
            DB::table('ai_visibility_checks')->insert(['brand_id' => $brand->id, 'batch' => $batch, 'prompt' => $prompt, 'status' => 'queued', 'requested_by' => $actor?->id, 'created_at' => now(), 'updated_at' => now()]);
        }
        RunAiVisibilityCheckJob::dispatch($batch);

        return $batch;
    }

    public function runBatch(string $batch): void
    {
        foreach (DB::table('ai_visibility_checks')->where('batch', $batch)->where('status', 'queued')->orderBy('id')->get() as $row) {
            $this->probe($row);
        }
    }

    public function probe(object $row): void
    {
        $brand = Brand::query()->find($row->brand_id);
        if ($brand === null) {
            return;
        }
        try {
            $route = $this->routes->resolve(AiRouteKeys::AI_VISIBILITY_PROBE);
            if ($route->isEmpty()) {
                throw new \RuntimeException('Uygun AI sağlayıcısı yok ya da aylık AI bütçesi doldu.');
            }
            $this->runtime->prepare(array_keys($route->providerModels));
            $response = (array) (new AiVisibilityProbeAgent)->prompt((string) $row->prompt, provider: $route->providerModels, timeout: 90)->toArray();
            $answer = mb_substr(trim(strip_tags((string) ($response['answer'] ?? ''))), 0, 2000);
            $businesses = array_values(array_slice(array_filter(array_map(static fn ($b): string => mb_substr(trim(strip_tags((string) $b)), 0, 160), (array) ($response['businesses'] ?? []))), 0, 15));
            [$mentioned, $position] = $this->detect($brand, $answer, $businesses);
            DB::table('ai_visibility_checks')->where('id', $row->id)->update([
                'status' => 'done', 'answer' => $answer, 'businesses' => json_encode($businesses, JSON_UNESCAPED_UNICODE), 'mentioned' => $mentioned, 'position' => $position,
                'competitors_mentioned' => json_encode($this->competitors($brand, $answer.' '.implode(' ', $businesses)), JSON_UNESCAPED_UNICODE),
                'provider' => $route->primaryProvider(), 'model' => $route->primaryModel(), 'checked_at' => now(), 'updated_at' => now(),
            ]);
        } catch (Throwable $exception) {
            DB::table('ai_visibility_checks')->where('id', $row->id)->update(['status' => 'failed', 'error' => mb_substr($exception->getMessage(), 0, 500), 'checked_at' => now(), 'updated_at' => now()]);
        }
    }

    /**
     * Brand named? By brand name / profile title (Turkish-folded, suffix tolerant) or site host; position is the
     * 1-based place in the recommended list.
     *
     * @param  list<string>  $businesses
     * @return array{0: bool, 1: ?int}
     */
    public function detect(Brand $brand, string $answer, array $businesses): array
    {
        $identity = $this->identity->for($brand);
        $names = array_values(array_unique(array_filter([(string) $brand->name, (string) ($identity['title'] ?? '')], static fn (string $n): bool => mb_strlen(trim($n)) >= 3)));
        $hosts = array_map(static fn (string $h): string => (string) preg_replace('/\.(com|net|org)(\.tr)?$|\.tr$/', '', $h), $identity['hosts']);
        $matches = static function (string $text) use ($names, $hosts): bool {
            foreach ($names as $name) {
                if (SeoText::matchesPhrase($text, $name)) {
                    return true;
                }
            }
            foreach ($hosts as $host) {
                if ($host !== '' && str_contains(mb_strtolower($text), $host)) {
                    return true;
                }
            }

            return false;
        };
        foreach ($businesses as $index => $business) {
            if ($matches($business)) {
                return [true, $index + 1];
            }
        }

        return [$matches($answer), null];
    }

    /** @return list<string> approved competitors named in the text */
    private function competitors(Brand $brand, string $text): array
    {
        return SearchDemandCompetitor::query()->where('brand_id', $brand->id)->where('status', 'approved')->get(['display_name', 'normalized_domain'])
            ->filter(fn ($c): bool => (mb_strlen((string) $c->display_name) >= 3 && SeoText::matchesPhrase($text, (string) $c->display_name))
                || str_contains(mb_strtolower($text), (string) preg_replace('/\.(com|net|org)(\.tr)?$|\.tr$/', '', (string) $c->normalized_domain)))
            ->map(fn ($c): string => (string) ($c->display_name ?: $c->normalized_domain))->values()->all();
    }

    /** @return list<array{batch: string, at: ?string, total: int, done: int, mentioned: int, rate: ?int}> newest first */
    public function history(Brand $brand, int $limit = 12): array
    {
        return DB::table('ai_visibility_checks')->where('brand_id', $brand->id)
            ->selectRaw("batch, min(created_at) as at, count(*) as total, sum(case when status = 'done' then 1 else 0 end) as done, sum(case when mentioned then 1 else 0 end) as mentioned_count")
            ->groupBy('batch')->orderByDesc('at')->limit($limit)->get()
            ->map(fn (object $r): array => ['batch' => (string) $r->batch, 'at' => $r->at, 'total' => (int) $r->total, 'done' => (int) $r->done, 'mentioned' => (int) $r->mentioned_count,
                'rate' => (int) $r->done > 0 ? (int) round((int) $r->mentioned_count / (int) $r->done * 100) : null])->all();
    }
}
