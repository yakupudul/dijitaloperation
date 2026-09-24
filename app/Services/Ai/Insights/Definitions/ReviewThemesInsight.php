<?php

namespace App\Services\Ai\Insights\Definitions;

use App\Ai\Agents\Insights\InsightAgent;
use App\Ai\Agents\Insights\ReviewThemesAgent;
use App\Models\Brand;
use App\Models\CoreAssetBinding;
use App\Models\DigitalAsset;
use App\Models\GbpReview;
use App\Services\Ai\Insights\BaseInsight;
use App\Support\Ai\AiRouteKeys;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/** "Yorum temaları" for a brand: own reviews versus the tracked competitors. */
final class ReviewThemesInsight extends BaseInsight
{
    public function kind(): string
    {
        return 'reviews.themes';
    }

    public function routeKey(): string
    {
        return AiRouteKeys::INSIGHT_REVIEW_THEMES;
    }

    public function label(): string
    {
        return 'Yorumlarda ne övülüyor, ne şikâyet ediliyor?';
    }

    public function tagStyles(): array
    {
        return [
            'complaint' => ['Şikâyet', 'bg-rose-50 text-rose-700 dark:bg-rose-500/10 dark:text-rose-300'],
            'competitor_edge' => ['Rakip önde', 'bg-amber-50 text-amber-700 dark:bg-amber-500/10 dark:text-amber-300'],
            'strength' => ['Güçlü yön', 'bg-emerald-50 text-emerald-700 dark:bg-emerald-500/10 dark:text-emerald-300'],
        ];
    }

    public function subjectClass(): string
    {
        return Brand::class;
    }

    public function agent(): InsightAgent
    {
        return new ReviewThemesAgent;
    }

    public function tokens(): array
    {
        return [9000, 1200];
    }

    public function context(Model $subject): array
    {
        /** @var Brand $subject */
        $profiles = DB::table('review_profiles')->where('brand_id', $subject->id)->where('active', true)
            ->orderByDesc('is_own')->orderByDesc('reviews_count')->limit(6)->get();
        $out = [];
        foreach ($profiles as $profile) {
            $out[] = [
                'name' => $profile->is_own ? 'BİZİM İŞLETME' : (string) $profile->title,
                'is_own' => (bool) $profile->is_own,
                'rating' => $profile->rating !== null ? (float) $profile->rating : null,
                'reviews_count' => $profile->reviews_count,
                'reviews' => DB::table('review_items')->where('review_profile_id', $profile->id)->whereNotNull('text')
                    ->orderByDesc('published_at')->limit($profile->is_own ? 80 : 40)->get(['rating', 'text'])
                    ->map(fn (object $r): array => ['stars' => $r->rating !== null ? (int) $r->rating : null, 'text' => mb_substr(trim((string) $r->text), 0, 400)])
                    ->filter(fn (array $r): bool => $r['text'] !== '')->values()->all(),
            ];
        }
        // Own Google reviews collected through the Business Profile connection, when review intel has none.
        if (! collect($out)->contains(fn (array $p): bool => $p['is_own'] && $p['reviews'] !== [])) {
            $own = $this->businessProfileReviews($subject);
            if ($own !== []) {
                $out = [['name' => 'BİZİM İŞLETME', 'is_own' => true, 'rating' => round(collect($own)->avg('stars') ?? 0, 2), 'reviews_count' => count($own), 'reviews' => $own],
                    ...array_values(array_filter($out, fn (array $p): bool => ! $p['is_own']))];
            }
        }
        if ($out === [] || collect($out)->every(fn (array $p): bool => $p['reviews'] === [])) {
            return ['error' => 'Yorum verisi yok. Önce "Rakip izleme › Yorumlar" üzerinden profilleri eşleyip yenile.'];
        }

        return ['brand' => $this->brandFacts($subject), 'profiles' => $out];
    }

    /** @return list<array{stars: ?int, text: string}> */
    private function businessProfileReviews(Brand $brand): array
    {
        $assetIds = DigitalAsset::query()->where('brand_id', $brand->id)->pluck('id');
        $resources = CoreAssetBinding::query()->whereIn('digital_asset_id', $assetIds)->where('status', CoreAssetBinding::STATUS_ACTIVE)->pluck('external_resource_id');
        $stars = ['ONE' => 1, 'TWO' => 2, 'THREE' => 3, 'FOUR' => 4, 'FIVE' => 5];

        return GbpReview::query()->where(fn ($q) => $q->whereIn('digital_asset_id', $assetIds)->orWhereIn('external_resource_id', $resources))
            ->whereNotNull('comment')->latest('id')->limit(80)->get(['star_rating', 'comment'])
            ->map(fn (GbpReview $r): array => ['stars' => $stars[strtoupper((string) $r->star_rating)] ?? null, 'text' => mb_substr(trim((string) $r->comment), 0, 400)])
            ->filter(fn (array $r): bool => $r['text'] !== '')->values()->all();
    }

    public function meta(Model $subject): array
    {
        /** @var Brand $subject */
        return ['brand_id' => $subject->id, 'digital_asset_id' => null, 'title' => 'Yorum temaları · '.$subject->name];
    }
}
