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
use App\Services\AiTasks\AiTaskQueue;
use App\Services\Archive\ProductionArchive;
use App\Services\Brand\BrandPack;
use App\Services\Epicrisis\Epicrisis;
use App\Services\Gbp\Desk\ReviewApprovals;
use App\Services\Gbp\Desk\ReviewDesk;
use App\Services\Prompts\PromptRegistry;
use App\Support\Ai\AiRouteKeys;
use App\Support\ServiceScope;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Faz 14: one-click AI reply drafts for the brand's own Google reviews. Drafts go to the production archive
 * (kind `gbp.review_reply`, versioned, 👍/👎); liked earlier replies of the brand are given to the model as tone
 * examples. Nothing is posted to Google (replying stays manual). The draft can be delegated to Claude over MCP: the job
 * then waits and runs again once Claude answered.
 */
final class ReviewReplyDrafter
{
    public const string KIND = 'gbp.review_reply';

    private const array STARS = ['ONE' => 1, 'TWO' => 2, 'THREE' => 3, 'FOUR' => 4, 'FIVE' => 5];

    public function __construct(
        private readonly AiRouteResolver $routes,
        private readonly AiProviderRuntimeConfig $runtime,
        private readonly ProductionArchive $archive,
        private readonly AiTaskQueue $tasks,
    ) {}

    /** Queue a draft; throws a validation error when no AI route is usable. */
    public function queue(GbpReview $review): void
    {
        if (! $this->tasks->delegated(AiRouteKeys::GBP_REVIEW_REPLY) && $this->routes->resolve(AiRouteKeys::GBP_REVIEW_REPLY)->isEmpty()) {
            throw ValidationException::withMessages(['reply' => 'Uygun AI sağlayıcısı yok ya da aylık AI bütçesi doldu (Ayarlar → AI).']);
        }
        Cache::put($this->stateKey((int) $review->id), 'running', now()->addMinutes(60));
        DraftReviewReplyJob::dispatch((int) $review->id);
    }

    /** Executed by the job; while Claude has not answered the draft stays "running" (the job runs again when it answered). */
    public function write(int $reviewId): void
    {
        $review = GbpReview::query()->find($reviewId);
        if ($review === null) {
            return;
        }
        $asset = $this->assetOf($review);
        $brand = $asset?->brand;
        try {
            if (! app(ServiceScope::class)->isAssetOperational($asset?->id)) {
                throw ServiceScope::notServed();
            }
            $agent = new ReviewReplyAgent;
            $context = $this->context($review, $brand) + Epicrisis::input($brand, 'reply');
            // Named by the review: the liked examples and recent openings drift while Claude works on a batch.
            $answer = $this->tasks->delegatedCall($agent, $context, 'review:'.$reviewId, 'REVIEW_JSON');
            if ($answer === 'queued') {
                Cache::put($this->stateKey($reviewId), 'running', now()->addDay());

                return;
            }
            if ($answer === 'error') {
                throw new \RuntimeException('Claude bu taslağı yazamadı; yeni taslak isteyin.');
            }
            if (is_array($answer)) {
                [$response, $provider, $model] = [$answer, AiTaskQueue::PROVIDER, AiTaskQueue::MODEL];
            } else {
                $route = $this->routes->resolve(AiRouteKeys::GBP_REVIEW_REPLY);
                if ($route->isEmpty()) {
                    throw new \RuntimeException('Uygun AI sağlayıcısı yok ya da aylık AI bütçesi doldu.');
                }
                $this->runtime->prepare(array_keys($route->providerModels));
                $response = (array) $agent->prompt('REVIEW_JSON'."\n".json_encode($context, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                    provider: $route->providerModels, timeout: 90)->toArray();
                [$provider, $model] = [$route->primaryProvider(), $route->primaryModel()];
            }
            $reply = mb_substr(trim(strip_tags((string) ($response['reply'] ?? ''))), 0, 1500);
            if ($reply === '') {
                throw new \RuntimeException('AI boş yanıt döndürdü.');
            }
            $wrong = self::voiceProblem($reply, $context);
            if ($wrong !== null) {
                throw new \RuntimeException($wrong.' Yeni taslak isteyin.');
            }
            $reply = self::signed($reply, $brand);
            $blocking = GbpAssistant::blockingHits($brand, $reply);
            if ($blocking !== []) {
                throw new \RuntimeException('Yanıt sektör uyum kuralına takıldı: '.implode(', ', $blocking).'. Yeni taslak isteyin.');
            }
            $this->archive->record(self::KIND, $review, [
                'reply' => $reply, 'tone' => (string) ($response['tone'] ?? 'neutral'),
                'provider' => $provider, 'model' => $model, 'prompt_version' => (string) $agent->promptVersionId(),
            ], ['brand_id' => $brand?->id, 'digital_asset_id' => $asset?->id, 'title' => 'Yorum yanıtı · '.mb_substr((string) $review->comment, 0, 60)]);
            Cache::forget($this->stateKey($reviewId));
        } catch (Throwable $exception) {
            Cache::put($this->stateKey($reviewId), 'failed: '.mb_substr($exception->getMessage(), 0, 200), now()->addHour());
        }
    }

    /** The Business Profile the review belongs to (its own asset, else the active binding of its location). */
    public function assetOf(GbpReview $review): ?DigitalAsset
    {
        return DigitalAsset::query()->find($review->digital_asset_id ?? CoreAssetBinding::query()->where('external_resource_id', $review->external_resource_id)
            ->where('status', CoreAssetBinding::STATUS_ACTIVE)->where('capability', 'google_business_profile')->value('digital_asset_id'));
    }

    public function state(int $reviewId): ?string
    {
        $state = Cache::get($this->stateKey($reviewId));

        return is_string($state) ? $state : null;
    }

    /**
     * state() of several reviews with one cache read.
     *
     * @param  list<int>  $reviewIds
     * @return array<int, ?string> review id => state
     */
    public function states(array $reviewIds): array
    {
        if ($reviewIds === []) {
            return [];
        }
        $keys = array_combine($reviewIds, array_map(fn (int $id): string => $this->stateKey($id), $reviewIds));
        $values = Cache::many(array_values($keys));

        return array_map(fn (string $key): ?string => is_string($values[$key] ?? null) ? $values[$key] : null, $keys);
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
            'business' => $brand !== null ? self::displayName((string) $brand->name) : null,
            'sector' => $brand?->sectorLabel(),
            'rating' => self::STARS[strtoupper((string) $review->star_rating)] ?? null,
            'review_text' => mb_substr(ReviewDesk::original((string) $review->comment), 0, 3000),
            'compliance' => BrandPack::rules($brand),
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

    /**
     * Review ids of this profile whose newest reply draft was written by AI with an older reply prompt and is still
     * open: not answered on Google, nothing on its way, no client decision on an approval link, not being drafted.
     * The night drafts them again (yakup 2026-11-14: the old drafts "hiç klinik cevabı gibi durmuyor"), newest
     * review first; drafts written or edited by hand are never touched.
     *
     * @return list<int>
     */
    public function staleReviewIds(int $assetId, int $limit): array
    {
        if ($limit <= 0) {
            return [];
        }
        $current = (string) app(PromptRegistry::class)->current(AiRouteKeys::GBP_REVIEW_REPLY)->id;
        $latest = AiProduction::query()->where('kind', self::KIND)->where('subject_type', class_basename(GbpReview::class))
            ->where('digital_asset_id', $assetId)->where('status', '!=', AiProduction::STATUS_DISCARDED)
            ->orderBy('version')->orderBy('id')->get(['id', 'subject_id', 'version', 'content', 'prompt_version'])->keyBy('subject_id');
        $ids = $latest->filter(fn (AiProduction $row): bool => ! (bool) data_get($row->content, 'manual') && (string) $row->prompt_version !== ''
            && (string) $row->prompt_version !== $current)->keys()->map(fn ($id): int => (int) $id)->values()->all();
        if ($ids === []) {
            return [];
        }
        $open = app(ReviewDesk::class)->openIds($ids);
        $decided = app(ReviewApprovals::class)->forReviews($open);
        $states = $this->states($open);
        $open = array_values(array_filter($open, fn (int $id): bool => ! isset($decided[$id]) && $states[$id] !== 'running'));
        if ($open === []) {
            return [];
        }

        return GbpReview::query()->whereIn('id', $open)->orderByDesc('create_time')->orderBy('id')->limit($limit)->pluck('id')
            ->map(fn ($id): int => (int) $id)->all();
    }

    /**
     * Why a written reply cannot be used, without AI: it thanks for stars / points (amateur and, for health brands, a
     * rating talk), or it gives a doctor a "Bey" / "Hanım" the brand's record does not hold (the model guessed the
     * gender: Burçin Öncül was "Burçin Hanım" in some replies). Null when the reply is fine.
     *
     * @param  array<string, mixed>  $context
     */
    public static function voiceProblem(string $reply, array $context): ?string
    {
        $business = mb_strtolower((string) ($context['business'] ?? ''));
        foreach (['yıldız', 'puan'] as $word) {
            if (! str_contains($business, $word) && preg_match('/'.$word.'/iu', $reply)) {
                return 'Yanıt yıldızdan / puandan söz ediyor.';
            }
        }
        $record = mb_strtolower(json_encode($context['brand_epicrisis'] ?? [], JSON_UNESCAPED_UNICODE) ?: '');
        if (preg_match_all('/(?<!\p{L})(\p{Lu}\p{Ll}+)\s+(Bey|Hanım|Hoca)(?!\p{L})/u', $reply, $hits, PREG_SET_ORDER)) {
            foreach ($hits as $hit) {
                if (! str_contains($record, mb_strtolower($hit[1].' '.$hit[2]))) {
                    return 'Yanıt "'.$hit[1].' '.$hit[2].'" diye hitap ediyor ama marka kaydında bu hitap yok.';
                }
            }
        }

        return null;
    }

    /** The reply with the business's sign-off as its last line ("Dr. Dt. Ad Soyad ve ekibi" / "Marka ekibi"). */
    public static function signed(string $reply, ?Brand $brand): string
    {
        $signature = self::signature($brand);
        if ($signature === '' || str_contains(mb_strtolower($reply), mb_strtolower($signature))) {
            return $reply;
        }

        return rtrim($reply)."\n\n".$signature;
    }

    public static function signature(?Brand $brand): string
    {
        $name = $brand !== null ? self::displayName((string) $brand->name) : '';
        if ($name === '') {
            return '';
        }

        return preg_match('/^(Dr|Dt|Uzm|Op|Prof|Doç)(?!\p{L})/u', $name) ? $name.' ve ekibi' : $name.' ekibi';
    }

    /** "Dr. Dt. Burçin ÖNCÜL" → "Dr. Dt. Burçin Öncül": all-caps words longer than four letters get Turkish title case. */
    public static function displayName(string $name): string
    {
        $name = trim(preg_replace('/\s+/u', ' ', $name) ?? $name);

        return (string) preg_replace_callback('/(?<!\p{L})\p{Lu}{5,}(?!\p{L})/u', function (array $m): string {
            $word = $m[0];
            $rest = mb_strtolower(strtr(mb_substr($word, 1), ['I' => 'ı', 'İ' => 'i']));

            return mb_substr($word, 0, 1).$rest;
        }, $name);
    }

    private function stateKey(int $reviewId): string
    {
        return 'review-reply:'.$reviewId;
    }
}
