<?php

namespace App\Services\Gbp;

use App\Ai\Agents\Site\ContentReviewAgent;
use App\Models\Brand;
use App\Models\GbpQueuedPost;
use App\Models\User;
use App\Services\Compliance\ForbiddenTerms;
use App\Services\Site\SiteAi;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Günde 1 tık (yakup, 2026-10-14): the planned Business Profile posts of the next days are read once more by AI
 * (`content.review`, one call per brand and batch). Every location keeps one post a day; the operator's daily click
 * approves only the posts that passed, and they go out on their day (ADR-078 publish). The rest stay as drafts with
 * the reason (the operator can still edit or approve them on the profile screen).
 */
final class GbpPostReview
{
    /** Days ahead (from today) that are checked. */
    public const int DAYS_AHEAD = 2;

    public const int BATCH = 15;

    /** @return Collection<int, GbpQueuedPost> drafts of the next days without a check */
    public static function unchecked(?int $brandId = null): Collection
    {
        return self::window($brandId)->whereNull('review')->get();
    }

    /** @return Builder<GbpQueuedPost> */
    private static function window(?int $brandId = null): Builder
    {
        $today = GbpPostQueue::today();

        return GbpQueuedPost::query()->where('status', GbpQueuedPost::DRAFT)
            ->whereBetween('publish_on', [$today->toDateString(), $today->addDays(self::DAYS_AHEAD)->toDateString()])
            ->when($brandId !== null, fn ($q) => $q->where('brand_id', $brandId))
            ->whereHas('digitalAsset', fn ($asset) => $asset->operational())
            ->orderBy('publish_on')->orderBy('id');
    }

    /** @return list<int> brands with unchecked drafts */
    public static function brandsToCheck(): array
    {
        return self::unchecked()->pluck('brand_id')->filter()->unique()->map(fn ($id): int => (int) $id)->values()->all();
    }

    /**
     * Checks the brand's unchecked drafts. Null while Claude (MCP) has not answered (the job runs again).
     *
     * @return array{checked: int}|null
     */
    public function review(int $brandId): ?array
    {
        $brand = Brand::query()->with('sectorCategory')->find($brandId);
        $posts = self::unchecked($brandId)->load('page', 'digitalAsset');
        if ($brand === null || $posts->isEmpty()) {
            return ['checked' => 0];
        }
        $checked = 0;
        foreach ($posts->chunk(self::BATCH) as $batch) {
            $items = $batch->values()->map(fn (GbpQueuedPost $post): array => ['id' => (int) $post->id, 'text' => (string) $post->summary, 'language' => 'tr',
                'source' => array_filter(['location' => (string) ($post->digitalAsset?->name ?? ''), 'page_title' => (string) ($post->page?->title ?: $post->page?->h1 ?? ''),
                    'page_url' => (string) $post->url, 'page_text' => $post->page?->aiText(1500)])])->all();
            $result = app(SiteAi::class)->run(new ContentReviewAgent, [
                'kind' => 'gbp_post',
                'brand' => ['name' => $brand->name, 'sector' => $brand->sectorCategory?->name],
                'forbidden' => ForbiddenTerms::forBrand($brand)->phrases(),
                'items' => $items,
            ], 240, 'gbp-review-'.$batch->first()->id);
            if ($result['status'] === 'queued') {
                return null;
            }
            if ($result['status'] !== 'ready') {
                continue; // no verdict: the next run tries again
            }
            $answers = collect((array) ($result['data']['items'] ?? []))->filter(fn ($i): bool => is_array($i) && is_int($i['id'] ?? null))->keyBy('id');
            foreach ($batch as $post) {
                $answer = (array) $answers->get((int) $post->id, []);
                $verdict = in_array($answer['verdict'] ?? null, ['ok', 'wrong', 'unsure'], true) ? (string) $answer['verdict'] : 'unsure';
                $post->forceFill(['review' => ['verdict' => $verdict, 'reason' => mb_substr(trim((string) ($answer['reason'] ?? 'AI yanıt vermedi.')), 0, 180),
                    'at' => now()->toIso8601String()]])->save();
                $checked++;
            }
        }

        return ['checked' => $checked];
    }

    /** @return Collection<int, GbpQueuedPost> checked drafts waiting for the daily click */
    public function ready(?int $brandId = null): Collection
    {
        return self::window($brandId)->get()->filter(fn (GbpQueuedPost $p): bool => ($p->review['verdict'] ?? null) === 'ok')->values();
    }

    /** @return Collection<int, GbpQueuedPost> drafts the check held back */
    public function held(?int $brandId = null): Collection
    {
        return self::window($brandId)->get()->filter(fn (GbpQueuedPost $p): bool => in_array($p->review['verdict'] ?? null, ['wrong', 'unsure'], true))->values();
    }

    /** The daily click: the checked drafts are approved and go out on their day. */
    public function approveReady(User $user, ?int $brandId = null): int
    {
        $ids = $this->ready($brandId)->pluck('id')->map(fn ($id): int => (int) $id)->all();

        return $ids === [] ? 0 : app(GbpPostQueue::class)->approve($user, $ids);
    }
}
