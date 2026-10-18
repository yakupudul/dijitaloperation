<?php

namespace App\Services\Epicrisis;

use App\Models\Brand;
use App\Models\BrandEpicrisisItem;
use App\Models\BrandMemory;
use App\Models\DigitalAsset;
use App\Models\GbpQueuedPost;
use App\Models\Suggestion;
use App\Services\SeoTasks\SeoText;
use App\Services\Work\ContentScorecard;
use Illuminate\Support\Collection;
use Throwable;

/**
 * Epikriz katman 4 — dersler, by rules, weekly: why the operator rejected the brand's suggestions (his own words, last
 * 120 days), what the last check keeps holding the brand's articles and posts for (a reason seen at least twice in 60
 * days) and which sent articles brought the most clicks (90 days). Every AI step reads them so the same mistake is not
 * made again and what worked is repeated.
 */
final class EpicrisisLessons
{
    public const int REJECTED_DAYS = 120;

    public const int CHECK_DAYS = 60;

    public const int CHECK_MIN = 2;

    private const int MAX = 6;

    public function __construct(private readonly EpicrisisStore $store) {}

    public function collect(Brand $brand): int
    {
        $rows = [];
        foreach ([fn (): array => $this->rejected($brand), fn (): array => $this->checks($brand), fn (): array => $this->works($brand)] as $source) {
            try {
                array_push($rows, ...$source());
            } catch (Throwable $exception) {
                report($exception);
            }
        }

        return count($this->store->sync($brand, BrandEpicrisisItem::LESSON, $rows, 'lessons'));
    }

    /** @return list<array<string, mixed>> */
    private function rejected(Brand $brand): array
    {
        return BrandMemory::query()->where('brand_id', $brand->id)->where('kind', 'decision')->where('updated_at', '>=', now()->subDays(self::REJECTED_DAYS))
            ->orderByDesc('updated_at')->limit(300)->get()
            ->filter(fn (BrandMemory $m): bool => data_get($m->data, 'decision') === 'reddedildi' && mb_strlen(trim((string) data_get($m->data, 'reason', ''))) >= 4)
            ->unique(fn (BrandMemory $m): string => SeoText::fold((string) data_get($m->data, 'reason')))
            ->take(self::MAX)->values()
            ->map(fn (BrandMemory $m): array => ['field' => 'rejected', 'value' => mb_substr(trim((string) data_get($m->data, 'reason')).' (öneri: '.trim((string) data_get($m->data, 'title')).')', 0, 300),
                'source_kind' => 'operator', 'source_ref' => 'Öneri #'.$m->ref_id, 'confidence' => BrandEpicrisisItem::VERIFIED, 'detail' => ['at' => (string) data_get($m->data, 'at')]])
            ->all();
    }

    /** @return list<array<string, mixed>> */
    private function checks(Brand $brand): array
    {
        $since = now()->subDays(self::CHECK_DAYS);
        $reasons = Suggestion::query()->where('brand_id', $brand->id)->where('updated_at', '>=', $since)->where('action->autopilot->review->verdict', 'wrong')
            ->limit(300)->get(['id', 'action'])->map(fn (Suggestion $s): string => (string) data_get($s->action, 'autopilot.review.reason'))
            ->merge(GbpQueuedPost::query()->where('brand_id', $brand->id)->where('updated_at', '>=', $since)->whereNotNull('review')->limit(500)->get(['id', 'review'])
                ->filter(fn (GbpQueuedPost $p): bool => ($p->review['verdict'] ?? null) === 'wrong')->map(fn (GbpQueuedPost $p): string => (string) ($p->review['reason'] ?? '')));

        return $this->repeated($reasons)->map(fn (array $g): array => ['field' => 'check', 'value' => $g['text'], 'source_kind' => 'rules', 'source_ref' => 'Son kontrol (içerik ve gönderiler)',
            'detail' => ['count' => $g['count']]])->all();
    }

    /**
     * Reasons that come back: grouped by their first three words after the item label ("Makale: …").
     *
     * @param  Collection<int, string>  $reasons
     * @return Collection<int, array{text: string, count: int}>
     */
    public function repeated(Collection $reasons): Collection
    {
        return $reasons->map(fn (string $r): string => trim((string) preg_replace('/^[^:]{1,40}:\s*/u', '', trim($r))))
            ->filter(fn (string $r): bool => mb_strlen($r) >= 8 && ! str_starts_with(SeoText::fold($r), 'yazi kisa'))
            ->groupBy(fn (string $r): string => implode(' ', array_slice(preg_split('/\s+/u', SeoText::fold($r)) ?: [], 0, 3)))
            ->filter(fn (Collection $g): bool => $g->count() >= self::CHECK_MIN)
            ->sortByDesc(fn (Collection $g): int => $g->count())->take(self::MAX)
            ->map(fn (Collection $g): array => ['text' => mb_substr((string) $g->first(), 0, 240), 'count' => $g->count()])->values();
    }

    /** @return list<array<string, mixed>> */
    private function works(Brand $brand): array
    {
        $cards = app(ContentScorecard::class);
        $rows = DigitalAsset::query()->where('brand_id', $brand->id)->where('type', 'website')->get()
            ->flatMap(fn (DigitalAsset $site): array => $cards->forSite($site))
            ->filter(fn (array $r): bool => $r['d90']['clicks'] >= 5)
            ->sortByDesc(fn (array $r): int => $r['d90']['clicks'])->take(3)->values();

        return $rows->map(fn (array $r): array => ['field' => 'works', 'value' => 'Tıklama getiren yazı: '.$r['title'], 'source_kind' => 'search', 'source_ref' => (string) ($r['url'] ?? ''),
            'detail' => ['clicks_90' => $r['d90']['clicks'], 'impressions_90' => $r['d90']['impressions']]])->all();
    }
}
