<?php

namespace App\Services\Brain\Proposals\Kinds;

use App\Ai\Agents\Brain\QueryServiceClassifierAgent;
use App\Models\BrainProposal;
use App\Models\SearchQueryLibraryItem;
use App\Models\ServiceCatalogItem;
use App\Models\ServiceMatchingKeyword;
use App\Models\User;
use App\Services\Brain\BrainAi;
use App\Services\Brain\EmbeddingService;
use App\Services\Brain\Proposals\ProposalKind;
use App\Services\Brain\Proposals\ProposalService;
use App\Services\Brain\ServiceProfiles;
use App\Services\SearchDemand\ServiceKeywordService;
use App\Support\Ai\AiRouteKeys;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * "Sorgu → hizmet": the four-tier assignment of library queries that have no service yet.
 *  1. Rules — the service's matching expressions. Exactly one hit is filed directly (as the intake always did).
 *  2. Similarity — the query's embedding vs each service's centroid (names, expressions, approved examples).
 *     A clear winner (large gap to the runner-up) becomes a proposal with that gap as its confidence.
 *  3. AI — only for queries still unclear (no vector, small gap, or several rule hits); it also labels the intent.
 *     Agreement between the AI and the similarity model raises confidence; disagreement lowers it.
 *  4. Operator — every proposal is reviewed in bulk; approved ones become examples, so the next run is sharper.
 */
final class QueryServiceKind implements ProposalKind
{
    public const string KIND = 'query_service';

    /** Similarity gap between the best and second-best service that counts as a clear winner. */
    private const float CLEAR_MARGIN = 0.08;

    private const float MIN_SIMILARITY = 0.45;

    public function __construct(
        private readonly BrainAi $ai,
        private readonly EmbeddingService $embeddings,
        private readonly ProposalService $proposals,
        private readonly ServiceProfiles $profiles,
        private readonly ServiceKeywordService $keywords,
    ) {}

    public function kind(): string
    {
        return self::KIND;
    }

    public function label(): string
    {
        return 'Sorgu → hizmet ataması';
    }

    public function usesAi(): bool
    {
        return true;
    }

    public function prepare(array $options): int
    {
        $sector = (string) ($options['sector'] ?? '');
        $queries = SearchQueryLibraryItem::query()->where('status', 'active')->where('is_branded', false)
            ->whereDoesntHave('services', fn ($s) => $s->where('status', 'active'))
            ->when($sector !== '', fn ($q) => $q->where(fn ($w) => $w->where('sector', $sector)->orWhereHas('sectors', fn ($s) => $s->where('code', $sector))))
            ->whereNotIn('id', BrainProposal::query()->where('kind', self::KIND)->whereIn('status', [BrainProposal::STATUS_PENDING, BrainProposal::STATUS_REJECTED, BrainProposal::STATUS_NOTHING])->select('subject_id'))
            ->with('sectors:id,code')->orderByDesc('id')->limit((int) ($options['limit'] ?? 400))->get();
        if ($queries->isEmpty()) {
            return 0;
        }
        $profiles = $this->profiles->all($sector !== '' ? [$sector] : null);
        if ($profiles->isEmpty()) {
            return 0;
        }
        $blocks = DB::table('library_query_service_blocks')->whereIn('query_id', $queries->pluck('id'))->get()->groupBy('query_id');
        $words = ServiceMatchingKeyword::query()->whereIn('service_catalog_item_id', $profiles->keys())->get();

        $created = 0;
        $unclear = [];
        $vectorGuess = [];
        $centroids = $this->centroids($profiles);
        $queryVectors = $centroids === null ? null : ($this->embeddings->embed($queries->pluck('canonical_text', 'id')->all()) ?? null);

        foreach ($queries as $query) {
            $allowed = $this->allowedServices($query, $profiles, $blocks->get($query->id, collect())->pluck('service_id')->map('intval')->all());
            if ($allowed === []) {
                continue;
            }
            $hits = array_values(array_intersect($this->keywords->matches($query->canonical_text, $allowed, $words), $allowed));
            if (count($hits) === 1) {
                $query->services()->syncWithoutDetaching([$hits[0] => ['is_primary' => true, 'provenance' => 'keyword_match']]);

                continue;
            }
            $ranked = $queryVectors !== null && isset($queryVectors[$query->id]) ? $this->rank($queryVectors[$query->id], $centroids, $hits !== [] ? $hits : $allowed) : [];
            $vectorGuess[$query->id] = $ranked;
            [$best, $second] = [$ranked[0] ?? null, $ranked[1] ?? null];
            $margin = $best !== null ? $best['score'] - ($second['score'] ?? 0.0) : 0.0;
            if ($best !== null && $best['score'] >= self::MIN_SIMILARITY && $margin >= self::CLEAR_MARGIN) {
                $created += $this->propose($query, $profiles->get($best['id']), null, 'vector',
                    min(0.95, 0.55 + $margin * 2.5), 'Anlam benzerliği en yüksek hizmet (fark: '.number_format($margin, 2).').') ? 1 : 0;

                continue;
            }
            $unclear[$query->id] = $allowed;
        }

        if ($unclear !== [] && $this->ai->available(AiRouteKeys::BRAIN_QUERY_CLASSIFIER)) {
            foreach (array_chunk($unclear, 60, true) as $chunk) {
                $created += $this->classify($queries->whereIn('id', array_keys($chunk)), $chunk, $profiles, $vectorGuess);
            }
        }

        return $created;
    }

    public function apply(BrainProposal $proposal, User $actor): void
    {
        $query = SearchQueryLibraryItem::query()->lockForUpdate()->findOrFail($proposal->subject_id);
        $serviceId = (int) ($proposal->proposed['service_id'] ?? 0);
        $service = ServiceCatalogItem::query()->where('status', 'active')->find($serviceId);
        if ($service === null || $query->status !== 'active') {
            throw new RuntimeException('Sorgu ya da hizmet artık aktif değil.');
        }
        if (DB::table('library_query_service_blocks')->where('query_id', $query->id)->where('service_id', $serviceId)->exists()) {
            throw new RuntimeException('Bu sorgu bu hizmete engellenmiş.');
        }
        $hasPrimary = $query->services()->wherePivot('is_primary', true)->exists();
        $query->services()->syncWithoutDetaching([$serviceId => ['is_primary' => ! $hasPrimary, 'provenance' => 'brain_review']]);
        $intent = $proposal->proposed['intent'] ?? null;
        $query->forceFill(array_filter([
            'search_intent' => is_string($intent) && $intent !== '' ? $intent : null,
            'classification_source' => 'brain_review',
            'classification_confidence' => $proposal->confidence !== null ? (int) round($proposal->confidence * 100) : null,
            'classified_at' => now(),
            'classified_by' => $actor->id,
        ], fn ($v): bool => $v !== null))->save();
    }

    /**
     * @param  Collection<int, SearchQueryLibraryItem>  $queries
     * @param  array<int, list<int>>  $allowed  query id => service ids
     * @param  Collection<int, array<string, mixed>>  $profiles
     * @param  array<int, list<array{id: int, score: float}>>  $vectorGuess
     */
    private function classify(Collection $queries, array $allowed, Collection $profiles, array $vectorGuess): int
    {
        $serviceIds = array_values(array_unique(array_merge(...array_values($allowed))));
        $answer = $this->ai->ask(new QueryServiceClassifierAgent, AiRouteKeys::BRAIN_QUERY_CLASSIFIER, [
            'services' => $profiles->only($serviceIds)->map(fn (array $p): array => [
                'id' => $p['id'], 'name' => $p['name'], 'description' => mb_substr($p['description'], 0, 200), 'examples' => array_slice($p['examples'], 0, 8),
            ])->values()->all(),
            'queries' => $queries->map(fn (SearchQueryLibraryItem $q): array => [
                'id' => (int) $q->id, 'text' => $q->canonical_text,
                'closest_by_similarity' => isset($vectorGuess[$q->id][0]) ? $profiles->get($vectorGuess[$q->id][0]['id'])['name'] ?? null : null,
            ])->values()->all(),
        ]);
        $created = 0;
        foreach ((array) ($answer['items'] ?? []) as $item) {
            $query = $queries->firstWhere('id', (int) ($item['query_id'] ?? 0));
            $serviceId = (int) ($item['service_id'] ?? 0);
            if ($query === null) {
                continue;
            }
            if ($serviceId === 0 || ! in_array($serviceId, $allowed[$query->id] ?? [], true)) {
                $this->proposals->nothing(self::KIND, 'search_query', (int) $query->id, $query->canonical_text.' → hizmet yok', trim((string) ($item['reason'] ?? '')), 'ai');

                continue;
            }
            $vectorTop = $vectorGuess[$query->id][0]['id'] ?? null;
            $confidence = match (true) {
                $vectorTop === null => 0.6,
                $vectorTop === $serviceId => 0.85,
                default => 0.45,
            };
            $intent = in_array($item['intent'] ?? null, QueryServiceClassifierAgent::INTENTS, true) ? $item['intent'] : null;
            $created += $this->propose($query, $profiles->get($serviceId), $intent, 'ai', $confidence,
                trim((string) ($item['reason'] ?? '')).($vectorTop !== null && $vectorTop !== $serviceId ? ' (Benzerlik modeli başka hizmet önerdi.)' : '')) ? 1 : 0;
        }

        return $created;
    }

    /** @param  array<string, mixed>|null  $service */
    private function propose(SearchQueryLibraryItem $query, ?array $service, ?string $intent, string $source, float $confidence, string $reason): bool
    {
        if ($service === null) {
            return false;
        }

        return $this->proposals->propose(self::KIND, 'search_query', (int) $query->id, null,
            $query->canonical_text.' → '.$service['name'], null,
            array_filter(['service_id' => $service['id'], 'service_name' => $service['name'], 'intent' => $intent], fn ($v): bool => $v !== null),
            $reason, $confidence, $source) !== null;
    }

    /**
     * Services the query may go to: those of its sectors (all when it has none), minus the blocked ones.
     *
     * @param  Collection<int, array<string, mixed>>  $profiles
     * @param  list<int>  $blocked
     * @return list<int>
     */
    private function allowedServices(SearchQueryLibraryItem $query, Collection $profiles, array $blocked): array
    {
        $sectors = array_filter([$query->sector, ...$query->sectors->pluck('code')->all()]);

        return $profiles->filter(fn (array $p): bool => $sectors === [] || in_array($p['sector'], $sectors, true))
            ->keys()->map('intval')->diff($blocked)->values()->all();
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $profiles
     * @return array<int, list<float>>|null
     */
    private function centroids(Collection $profiles): ?array
    {
        if (! $this->embeddings->available()) {
            return null;
        }
        $texts = [];
        foreach ($profiles as $id => $profile) {
            foreach (ServiceProfiles::texts($profile) as $i => $text) {
                $texts[$id.':'.$i] = $text;
            }
        }
        $vectors = $this->embeddings->embed($texts);
        if ($vectors === null) {
            return null;
        }
        $grouped = [];
        foreach ($vectors as $key => $vector) {
            $grouped[(int) explode(':', (string) $key)[0]][] = $vector;
        }

        return array_map(fn (array $list): array => EmbeddingService::centroid($list), $grouped);
    }

    /**
     * @param  list<float>  $vector
     * @param  array<int, list<float>>  $centroids
     * @param  list<int>  $candidates
     * @return list<array{id: int, score: float}>
     */
    private function rank(array $vector, array $centroids, array $candidates): array
    {
        $scores = [];
        foreach ($candidates as $id) {
            if (isset($centroids[$id])) {
                $scores[] = ['id' => $id, 'score' => EmbeddingService::similarity($vector, $centroids[$id])];
            }
        }
        usort($scores, fn (array $a, array $b): int => $b['score'] <=> $a['score']);

        return $scores;
    }
}
