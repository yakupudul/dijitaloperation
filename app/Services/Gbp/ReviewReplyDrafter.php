<?php

namespace App\Services\Gbp;

use App\Ai\Agents\ReviewReplyAgent;
use App\Jobs\DraftReviewReplyJob;
use App\Models\AiProduction;
use App\Models\Brand;
use App\Models\CoreAssetBinding;
use App\Models\DigitalAsset;
use App\Models\GbpReview;
use App\Services\Ai\AiCostEstimator;
use App\Services\Ai\AiProviderRuntimeConfig;
use App\Services\Ai\AiRouteResolver;
use App\Services\Archive\ProductionArchive;
use App\Services\Compliance\SectorPackRegistry;
use App\Services\Gbp\Desk\ReviewDesk;
use App\Support\Ai\AiRouteKeys;
use App\Support\ServiceScope;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Faz 14: one-click AI reply drafts for the brand's own Google reviews. Drafts go to the production archive
 * (kind `gbp.review_reply`, versioned, 👍/👎); liked earlier replies of the brand are given to the model as tone
 * examples. Nothing is posted to Google (replying stays manual).
 */
final class ReviewReplyDrafter
{
    public const string KIND = 'gbp.review_reply';

    private const array STARS = ['ONE' => 1, 'TWO' => 2, 'THREE' => 3, 'FOUR' => 4, 'FIVE' => 5];

    public function __construct(
        private readonly AiRouteResolver $routes,
        private readonly AiProviderRuntimeConfig $runtime,
        private readonly ProductionArchive $archive,
    ) {}

    /** Queue a draft; throws a validation error when no AI route is usable. */
    public function queue(GbpReview $review): void
    {
        if ($this->routes->resolve(AiRouteKeys::GBP_REVIEW_REPLY)->isEmpty()) {
            throw ValidationException::withMessages(['reply' => 'Uygun AI sağlayıcısı yok ya da aylık AI bütçesi doldu (Ayarlar → AI).']);
        }
        Cache::put($this->stateKey((int) $review->id), 'running', now()->addMinutes(60));
        DraftReviewReplyJob::dispatch((int) $review->id);
    }

    public function write(int $reviewId): void
    {
        $review = GbpReview::query()->find($reviewId);
        if ($review === null) {
            return;
        }
        $asset = DigitalAsset::query()->find($review->digital_asset_id ?? CoreAssetBinding::query()->where('external_resource_id', $review->external_resource_id)
            ->where('status', CoreAssetBinding::STATUS_ACTIVE)->where('capability', 'google_business_profile')->value('digital_asset_id'));
        $brand = $asset?->brand;
        try {
            if (! app(ServiceScope::class)->isAssetOperational($asset?->id)) {
                throw ServiceScope::notServed();
            }
            $route = $this->routes->resolve(AiRouteKeys::GBP_REVIEW_REPLY);
            if ($route->isEmpty()) {
                throw new \RuntimeException('Uygun AI sağlayıcısı yok ya da aylık AI bütçesi doldu.');
            }
            $this->runtime->prepare(array_keys($route->providerModels));
            $agent = new ReviewReplyAgent;
            $response = (array) $agent->prompt('REVIEW_JSON'."\n".json_encode($this->context($review, $brand), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                provider: $route->providerModels, timeout: 90)->toArray();
            $reply = mb_substr(trim(strip_tags((string) ($response['reply'] ?? ''))), 0, 1500);
            if ($reply === '') {
                throw new \RuntimeException('AI boş yanıt döndürdü.');
            }
            $blocking = GbpAssistant::blockingHits($brand, $reply);
            if ($blocking !== []) {
                throw new \RuntimeException('Yanıt sektör uyum kuralına takıldı: '.implode(', ', $blocking).'. Yeni taslak isteyin.');
            }
            $this->archive->record(self::KIND, $review, [
                'reply' => $reply, 'tone' => (string) ($response['tone'] ?? 'neutral'),
                'provider' => $route->primaryProvider(), 'model' => $route->primaryModel(), 'prompt_version' => (string) $agent->promptVersionId(),
            ], ['brand_id' => $brand?->id, 'digital_asset_id' => $asset?->id, 'title' => 'Yorum yanıtı · '.mb_substr((string) $review->comment, 0, 60)]);
            Cache::forget($this->stateKey($reviewId));
        } catch (Throwable $exception) {
            Cache::put($this->stateKey($reviewId), 'failed: '.mb_substr($exception->getMessage(), 0, 200), now()->addHour());
        }
    }

    public function state(int $reviewId): ?string
    {
        $state = Cache::get($this->stateKey($reviewId));

        return is_string($state) ? $state : null;
    }

    /** Clears a failed draft note (the operator deleted the review's drafts). */
    public function forget(int $reviewId): void
    {
        if (str_starts_with((string) $this->state($reviewId), 'failed')) {
            Cache::forget($this->stateKey($reviewId));
        }
    }

    /** Rough cost of one draft with the current route (shown before the click). */
    public function estimate(): ?string
    {
        return app(AiCostEstimator::class)->label(AiRouteKeys::GBP_REVIEW_REPLY, 2500, 350);
    }

    /** @return array<string, mixed> */
    private function context(GbpReview $review, ?Brand $brand): array
    {
        return [
            'business' => $brand?->name,
            'rating' => self::STARS[strtoupper((string) $review->star_rating)] ?? null,
            'review_text' => mb_substr(ReviewDesk::original((string) $review->comment), 0, 3000),
            'compliance' => $brand !== null ? app(SectorPackRegistry::class)->rulesForBrand($brand)->pluck('message')->unique()->values()->take(8)->all() : [],
            'liked_examples' => $brand !== null ? $this->archive->likedExamples(self::KIND, (int) $brand->id, 'reply') : [],
            'recent_openings' => $brand !== null ? $this->recentOpenings((int) $brand->id, (int) $review->id) : [],
        ];
    }

    /**
     * The first sentence of the brand's latest reply drafts (other reviews): the model must not open the same way, so
     * replies drafted in one go do not all start with the same thank-you line.
     *
     * @return list<string>
     */
    private function recentOpenings(int $brandId, int $reviewId): array
    {
        return AiProduction::query()->where('kind', self::KIND)->where('brand_id', $brandId)
            ->where(fn ($query) => $query->where('subject_id', '!=', $reviewId)->orWhere('subject_type', '!=', class_basename(GbpReview::class)))
            ->orderByDesc('id')->limit(12)->get(['content'])
            ->map(fn (AiProduction $row): string => mb_substr((string) preg_split('/(?<=[.!?])\s/u', trim((string) data_get($row->content, 'reply', '')))[0], 0, 120))
            ->filter()->unique()->values()->all();
    }

    private function stateKey(int $reviewId): string
    {
        return 'review-reply:'.$reviewId;
    }
}
