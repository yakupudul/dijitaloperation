<?php

namespace App\Services\Brain\Proposals\Kinds;

use App\Models\BrainProposal;
use App\Models\ServiceCatalogItem;
use App\Models\ServiceMatchingKeyword;
use App\Models\User;
use App\Services\Brain\Proposals\ProposalKind;
use App\Services\Brain\Proposals\ProposalService;
use App\Services\SearchDemand\ServiceKeywordService;
use App\Support\Options\LocationOptions;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * "Eşleme ifadesi önerisi" — no AI. Learns new matching expressions from the queries operators already filed under a
 * service: a one- or two-word phrase that appears in at least MIN_SUPPORT of the service's queries, almost never in
 * other services' queries of the same sector (precision ≥ MIN_PRECISION), and would catch queries the current
 * expressions miss. Approving adds it to the service, so the rule tier assigns more queries without AI next time.
 */
final class MatchingKeywordKind implements ProposalKind
{
    public const string KIND = 'matching_keyword';

    private const int MIN_SUPPORT = 3;

    private const float MIN_PRECISION = 0.85;

    private const int MIN_GAIN = 2;

    private const array STOPWORDS = ['ve', 'ile', 'icin', 'mi', 'mu', 'ne', 'kac', 'bir', 'en', 'cok', 'var', 'yok', 'olan', 'gibi', 'sonra', 'once', 'the', 'and', 'for', 'near', 'yakinimda', 'yakin'];

    public function __construct(
        private readonly ProposalService $proposals,
        private readonly ServiceKeywordService $keywords,
    ) {}

    public function kind(): string
    {
        return self::KIND;
    }

    public function label(): string
    {
        return 'Yeni eşleme ifadeleri';
    }

    public function usesAi(): bool
    {
        return false;
    }

    public function prepare(array $options): int
    {
        $rows = DB::table('search_query_library_item_service as s')
            ->join('search_query_library_items as q', 'q.id', '=', 's.search_query_library_item_id')
            ->join('service_catalog_items as c', 'c.id', '=', 's.service_catalog_item_id')
            ->whereIn('s.provenance', ['operator', 'brain_review', 'operator_alias', 'keyword_match'])
            ->where('q.status', 'active')->whereNull('q.deleted_at')->where('c.status', 'active')
            ->get(['s.service_catalog_item_id as service_id', 'c.sector', 'q.canonical_text']);
        if ($rows->isEmpty()) {
            return 0;
        }
        $words = ServiceMatchingKeyword::query()->whereIn('service_catalog_item_id', $rows->pluck('service_id')->unique())->get()->groupBy('service_catalog_item_id');
        $created = 0;
        foreach ($rows->groupBy('sector') as $sectorRows) {
            /** @var array<string, array<int, int>> $counts phrase => service id => query count */
            $counts = [];
            /** @var array<string, array<int, int>> $gain phrase => service id => queries the current expressions miss */
            $gain = [];
            foreach ($sectorRows as $row) {
                $serviceId = (int) $row->service_id;
                $missed = $this->keywords->matches((string) $row->canonical_text, [$serviceId], $words->get($serviceId, collect())) === [];
                foreach ($this->phrases((string) $row->canonical_text) as $phrase) {
                    $counts[$phrase][$serviceId] = ($counts[$phrase][$serviceId] ?? 0) + 1;
                    if ($missed) {
                        $gain[$phrase][$serviceId] = ($gain[$phrase][$serviceId] ?? 0) + 1;
                    }
                }
            }
            $perService = [];
            foreach ($counts as $phrase => $byService) {
                $total = array_sum($byService);
                foreach ($byService as $serviceId => $count) {
                    $precision = $count / $total;
                    if ($count >= self::MIN_SUPPORT && $precision >= self::MIN_PRECISION && ($gain[$phrase][$serviceId] ?? 0) >= self::MIN_GAIN) {
                        $perService[$serviceId][] = ['phrase' => $phrase, 'count' => $count, 'gain' => $gain[$phrase][$serviceId], 'precision' => $precision];
                    }
                }
            }
            foreach ($perService as $serviceId => $candidates) {
                $service = ServiceCatalogItem::query()->with('primaryName')->find($serviceId);
                if ($service === null) {
                    continue;
                }
                // Most new queries caught first; on a tie the more specific (two-word) phrase wins.
                usort($candidates, fn (array $a, array $b): int => [$b['gain'], substr_count($b['phrase'], ' '), $b['count']] <=> [$a['gain'], substr_count($a['phrase'], ' '), $a['count']]);
                // A single word inside an already kept phrase adds nothing unless it catches more queries on its own.
                $kept = [];
                foreach ($candidates as $candidate) {
                    foreach ($kept as $k) {
                        if (str_contains(' '.$k['phrase'].' ', ' '.$candidate['phrase'].' ') && $candidate['gain'] <= $k['gain']) {
                            continue 2;
                        }
                    }
                    $kept[] = $candidate;
                    if (count($kept) >= 8) {
                        break;
                    }
                }
                foreach ($kept as $candidate) {
                    $created += $this->proposals->propose(self::KIND, 'service', (int) $serviceId, null,
                        '"'.$candidate['phrase'].'" → '.($service->primaryName?->raw_label ?? '#'.$serviceId),
                        null, ['label' => $candidate['phrase'], 'service_id' => (int) $serviceId, 'service_name' => (string) ($service->primaryName?->raw_label ?? '')],
                        $candidate['count'].' onaylı sorguda geçiyor, bunların '.$candidate['gain'].' tanesini bugünkü ifadeler yakalamıyor; diğer hizmetlerde neredeyse hiç geçmiyor.',
                        $candidate['precision'], 'system') !== null ? 1 : 0;
                }
            }
        }

        return $created;
    }

    public function apply(BrainProposal $proposal, User $actor): void
    {
        $service = ServiceCatalogItem::query()->where('status', 'active')->findOrFail((int) $proposal->subject_id);
        $added = $this->keywords->append($service, [(string) ($proposal->proposed['label'] ?? '')]);
        if ($added === []) {
            throw new RuntimeException('İfade eklenemedi: zaten var, çok genel ya da hizmetin ifade sınırı (200) doldu.');
        }
    }

    /** @return list<string> distinct one- and two-word phrases of a query, places and filler words removed */
    private function phrases(string $text): array
    {
        $folded = LocationOptions::fold(LocationOptions::strip($text)['text']);
        $words = array_values(array_filter(preg_split('/[^a-z0-9]+/', $folded) ?: [], fn (string $w): bool => $w !== '' && ! in_array($w, self::STOPWORDS, true) && ! ctype_digit($w)));
        $phrases = [];
        foreach ($words as $i => $word) {
            if (mb_strlen($word) >= 4 && ! ServiceKeywordService::isGeneric($word)) {
                $phrases[] = $word;
            }
            if (isset($words[$i + 1])) {
                $pair = $word.' '.$words[$i + 1];
                if (! ServiceKeywordService::isGeneric($pair)) {
                    $phrases[] = $pair;
                }
            }
        }

        return array_values(array_unique($phrases));
    }
}
