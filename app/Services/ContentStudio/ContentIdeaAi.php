<?php

namespace App\Services\ContentStudio;

use App\Ai\Agents\Content\ContentIdeaAgent;
use App\Jobs\GenerateContentIdeasJob;
use App\Models\BrandOffering;
use App\Models\ContentIdea;
use App\Models\DigitalAsset;
use App\Models\TopicCluster;
use App\Models\User;
use App\Services\Ai\AiCostEstimator;
use App\Services\Ai\AiProviderRuntimeConfig;
use App\Services\Ai\AiRouteResolver;
use App\Services\Archive\ProductionArchive;
use App\Services\SiteFixes\SiteFixAi;
use App\Support\Ai\AiRouteKeys;
use App\Support\ServiceScope;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * "Konu üret" (Faz 4): the operator picks services (axes) and a count. Ideas come from the topic map first
 * (uncovered topics, real demand); only the gap is asked of the AI — one call per batch, never per idea — with every
 * existing title of the site and of the idea list so nothing already written is proposed again. AI ideas pass the same
 * dedupe and sector rules and get real internal links. Runs queued.
 */
final class ContentIdeaAi
{
    public function __construct(
        private readonly AiRouteResolver $routes,
        private readonly AiProviderRuntimeConfig $runtime,
        private readonly ProductionArchive $archive,
    ) {}

    /** @param  list<int>  $offeringIds */
    public function queue(DigitalAsset $site, array $offeringIds, int $count, ?User $user): void
    {
        app(ServiceScope::class)->ensureAssetServed($site, 'ideas');
        $offeringIds = $this->validOfferings($site, $offeringIds);
        if ($offeringIds === []) {
            throw ValidationException::withMessages(['ideas' => 'En az bir hizmet seç.']);
        }
        $max = (int) config('moxdop-content.ideas.max_per_request', 60);
        if ($count < 1 || $count > $max) {
            throw ValidationException::withMessages(['ideas' => 'Konu sayısı 1 ile '.$max.' arasında olmalı.']);
        }
        Cache::put($this->stateKey($site->id), 'running', now()->addMinutes(15));
        GenerateContentIdeasJob::dispatch((int) $site->id, $offeringIds, $count, $user?->id);
    }

    public function state(int $siteId): ?string
    {
        $state = Cache::get($this->stateKey($siteId));

        return is_string($state) ? $state : null;
    }

    public function clearState(int $siteId): void
    {
        Cache::forget($this->stateKey($siteId));
    }

    /** @param  list<int>  $offeringIds */
    public function run(int $siteId, array $offeringIds, int $count, ?int $userId = null): void
    {
        try {
            $site = DigitalAsset::query()->where('type', 'website')->findOrFail($siteId);
            if (! app(ServiceScope::class)->isAssetOperational($site->id)) {
                throw ServiceScope::notServed();
            }
            $user = $userId !== null ? User::query()->find($userId) : null;
            $planner = app(ContentIdeaPlanner::class);
            $fromMap = $planner->fromClusters($site, $offeringIds, $count, false);
            $total = count($fromMap['ideas']);
            $fromAi = 0;
            $remaining = $count - $total;
            $note = '';
            if ($remaining > 0) {
                $route = $this->routes->resolve(AiRouteKeys::CONTENT_IDEAS);
                if ($route->isEmpty()) {
                    $note = ' AI kapalı ya da bütçe doldu; kalan '.$remaining.' konu önerilemedi.';
                } else {
                    $this->runtime->prepare(array_keys($route->providerModels));
                    $batchSize = max(5, (int) config('moxdop-content.ideas.ai_batch_size', 30));
                    // One call per batch, each batch asked once (a little over-asked: some ideas fall to dedupe / rules).
                    $gap = $remaining;
                    for ($offset = 0; $offset < $gap && $remaining > 0; $offset += $batchSize) {
                        $batch = min($batchSize, $gap - $offset);
                        $input = $this->input($site, $planner, $offeringIds, $batch + max(1, intdiv($batch, 5)));
                        $response = (array) (new ContentIdeaAgent)->prompt("INPUT_JSON\n".json_encode($input, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR),
                            provider: $route->providerModels, timeout: 240)->toArray();
                        $raw = array_values(array_filter((array) ($response['ideas'] ?? []), 'is_array'));
                        $stored = $planner->storeAiIdeas($site, array_slice($raw, 0, $batch * 2), $user, min($batch, $remaining));
                        $this->archive->record('content.ideas', $site, ['ideas' => $raw, 'kept' => array_map(fn (ContentIdea $i): string => $i->title, $stored), 'provider' => $route->primaryModel(), 'prompt_version' => ContentIdeaAgent::PROMPT_VERSION],
                            ['brand_id' => $site->brand_id, 'digital_asset_id' => $site->id, 'title' => 'Konu önerileri · '.count($stored), 'provider' => $route->primaryProvider(), 'model' => $route->primaryModel()]);
                        $fromAi += count($stored);
                        $remaining -= count($stored);
                    }
                    if ($remaining > 0) {
                        $note = ' '.$remaining.' konu için yeni, tekrar etmeyen öneri çıkmadı.';
                    }
                }
            }
            Cache::put($this->stateKey($siteId), 'done: '.($total + $fromAi).' konu hazır ('.$total.' konu haritasından, '.$fromAi.' AI boşluk önerisi).'
                .($fromMap['skipped_existing'] > 0 ? ' '.$fromMap['skipped_existing'].' konu sitede zaten yazılı olduğu için önerilmedi.' : '').$note, now()->addHour());
        } catch (Throwable $exception) {
            report($exception);
            Cache::put($this->stateKey($siteId), 'failed: '.mb_substr($exception->getMessage(), 0, 200), now()->addHour());
        }
    }

    public function estimate(int $count): ?string
    {
        $batches = max(1, (int) ceil($count / max(5, (int) config('moxdop-content.ideas.ai_batch_size', 30))));
        $cost = app(AiCostEstimator::class)->cost(AiRouteKeys::CONTENT_IDEAS, 9000, 6000);

        return $cost === null ? null : ($cost <= 0 ? 'ücretsiz model' : '~$'.number_format(max($cost * $batches, 0.0001), 2, ',', '.'));
    }

    /**
     * @param  list<int>  $offeringIds
     * @return array<string, mixed>
     */
    private function input(DigitalAsset $site, ContentIdeaPlanner $planner, array $offeringIds, int $count): array
    {
        $services = array_intersect_key($planner->services($site), array_flip($offeringIds));
        $demand = [];
        foreach ($services as $id => $service) {
            $clusters = TopicCluster::query()->where('digital_asset_id', $site->id)->where('brand_offering_id', $id)->whereIn('status', ['active'])
                ->orderByDesc('demand_score')->limit(15)->with(['queries' => fn ($q) => $q->limit(5)])->get();
            $demand[] = ['service' => $service['name'], 'topics' => $clusters->map(fn (TopicCluster $c): array => ['topic' => $c->label, 'covered' => $c->coverage === 'covered',
                'queries' => $c->queries->pluck('query')->all()])->values()->all()];
        }
        $titles = array_values(array_filter(array_merge(
            array_column($planner->inventory($site)->items(), 'title'),
            ContentIdea::query()->where('digital_asset_id', $site->id)->where('status', '!=', 'removed')->pluck('title')->all(),
        )));

        return [
            'business' => app(SiteFixAi::class)->businessFacts($site),
            'services' => array_values(array_column($services, 'name')),
            'count' => $count,
            'demand' => $demand,
            'existing_titles' => array_slice(array_values(array_unique($titles)), 0, 600),
            'compliance' => $planner->compliance($site)->forPrompt(),
        ];
    }

    /**
     * @param  list<int>  $offeringIds
     * @return list<int>
     */
    private function validOfferings(DigitalAsset $site, array $offeringIds): array
    {
        return BrandOffering::query()->where('brand_id', $site->brand_id)->where('status', 'active')->whereIn('id', array_map('intval', $offeringIds) ?: [0])
            ->orderBy('id')->pluck('id')->map(fn ($id): int => (int) $id)->all();
    }

    private function stateKey(int $siteId): string
    {
        return 'content-ideas:'.$siteId;
    }
}
