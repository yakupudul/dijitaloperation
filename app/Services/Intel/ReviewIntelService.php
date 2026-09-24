<?php

namespace App\Services\Intel;

use App\Enums\CustomerStatus;
use App\Models\Brand;
use App\Models\Intel\BrandIntelSetting;
use App\Models\Intel\MapGridRun;
use App\Services\Integrations\DataForSeo\DataForSeoEndpointAllowlist;
use App\Services\SeoTasks\SeoText;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Review intelligence (Faz 8d): Google reviews of the brand and the businesses that beat it in the latest map
 * grid scan (plus any added by hand), via DataForSEO's reviews endpoint (standard queue, inside the brand cap).
 * Rating trend, review velocity, owner response rate and recurring words in negative reviews — the owner's
 * decision to watch competitor reviews for internal use; reviewer names are never stored.
 */
final class ReviewIntelService implements DataForSeoTaskHandler
{
    public const string GET_PREFIX = 'business_data/google/reviews/task_get';

    public function __construct(
        private readonly DataForSeoTaskQueue $queue,
        private readonly BrandGbpIdentity $identity,
    ) {}

    /** Own profile + top competitors of the latest grid scan; manual rows are kept. Returns active profile count. */
    public function syncProfiles(Brand $brand): int
    {
        $identity = $this->identity->for($brand);
        $own = DB::table('review_profiles')->where('brand_id', $brand->id)->where('is_own', true)->first();
        $values = ['title' => mb_substr((string) $identity['title'], 0, 200), 'cid' => $identity['cid'], 'place_id' => $identity['place_id'], 'updated_at' => now()];
        $own === null
            ? DB::table('review_profiles')->insert($values + ['brand_id' => $brand->id, 'is_own' => true, 'source' => 'profile', 'created_at' => now()])
            : DB::table('review_profiles')->where('id', $own->id)->update($values);

        $run = MapGridRun::query()->where('brand_id', $brand->id)->whereIn('status', [MapGridRun::STATUS_COMPLETED, MapGridRun::STATUS_PARTIAL])->latest('started_at')->first();
        if ($run !== null) {
            $max = (int) config('moxdop-intel.reviews.max_competitors', 5);
            $picked = 0;
            foreach (MapGridService::competitors($run, 30) as $row) {
                $cid = null;
                foreach ($run->points()->where('status', 'done')->get(['results']) as $point) {
                    foreach ((array) $point->results as $item) {
                        if ((string) $item['title'] === $row['title'] && filled($item['cid'] ?? null)) {
                            $cid = (string) $item['cid'];
                            break 2;
                        }
                    }
                }
                if ($row['ours'] || $cid === null || $picked >= $max) {
                    continue;
                }
                $picked++;
                if (! DB::table('review_profiles')->where('brand_id', $brand->id)->where('cid', $cid)->exists()) {
                    DB::table('review_profiles')->insert(['brand_id' => $brand->id, 'is_own' => false, 'title' => mb_substr($row['title'], 0, 200), 'cid' => $cid, 'source' => 'grid', 'created_at' => now(), 'updated_at' => now()]);
                }
            }
        }

        return DB::table('review_profiles')->where('brand_id', $brand->id)->where('active', true)->count();
    }

    public function addManual(Brand $brand, string $title, string $cid): void
    {
        if (preg_match('/^\d{5,25}$/', $cid) !== 1 || trim($title) === '') {
            throw ValidationException::withMessages(['reviews' => 'Ad ve sayısal CID gerekli (Haritalar bağlantısındaki cid=…).']);
        }
        DB::table('review_profiles')->updateOrInsert(['brand_id' => $brand->id, 'cid' => $cid], ['title' => mb_substr(trim($title), 0, 200), 'is_own' => false, 'source' => 'manual', 'active' => true, 'created_at' => now(), 'updated_at' => now()]);
    }

    public function estimate(Brand $brand): float
    {
        $count = max(1, DB::table('review_profiles')->where('brand_id', $brand->id)->where('active', true)->count());

        return round($count * (float) config('moxdop-intel.reviews.cost_per_task_usd', 0.004), 4);
    }

    public function refresh(Brand $brand): int
    {
        if (! $this->queue->available()) {
            throw ValidationException::withMessages(['reviews' => 'DataForSEO bağlantısı yok.']);
        }
        $this->syncProfiles($brand);
        $settings = BrandIntelSetting::for($brand);
        $spent = $this->queue->spentThisMonth((int) $brand->id);
        if ($spent + $this->estimate($brand) > (float) $settings->monthly_usd) {
            throw ValidationException::withMessages(['reviews' => sprintf('Aylık tavan aşılır: bu ay %.2f USD harcandı, yorum okuma ≈ %.3f USD, tavan %.2f USD.', $spent, $this->estimate($brand), (float) $settings->monthly_usd)]);
        }
        $items = [];
        foreach (DB::table('review_profiles')->where('brand_id', $brand->id)->where('active', true)->get() as $profile) {
            $target = filled($profile->cid) ? ['cid' => (string) $profile->cid] : (filled($profile->place_id) ? ['place_id' => (string) $profile->place_id] : ['keyword' => (string) $profile->title, 'location_code' => 2792]);
            $items[] = [
                'payload' => $target + ['language_code' => 'tr', 'depth' => (int) config('moxdop-intel.reviews.depth', 50), 'sort_by' => 'newest', 'tag' => 'reviews:'.$profile->id],
                'subject_type' => 'review_profile', 'subject_id' => (int) $profile->id,
            ];
        }
        $this->queue->post(DataForSeoEndpointAllowlist::BUSINESS_DATA_GOOGLE_REVIEWS_TASK_POST, self::GET_PREFIX, 'reviews', (int) $brand->id, $items);
        $settings->forceFill(['reviews_refreshed_at' => now()])->save();

        return count($items);
    }

    public function runDue(): array
    {
        $stats = ['refreshed' => 0, 'skipped' => 0];
        $every = (int) config('moxdop-intel.reviews.every_days', 14);
        $settings = BrandIntelSetting::query()->where('reviews_enabled', true)
            ->where(fn ($q) => $q->whereNull('reviews_refreshed_at')->orWhere('reviews_refreshed_at', '<', now()->subDays($every)))
            ->whereHas('brand.customer', fn ($q) => $q->where('status', CustomerStatus::Active->value))->with('brand')->get();
        foreach ($settings as $setting) {
            try {
                $this->refresh($setting->brand);
                $stats['refreshed']++;
            } catch (ValidationException) {
                $stats['skipped']++;
            }
        }

        return $stats;
    }

    public function handleResult(object $task, array $result): void
    {
        $profile = DB::table('review_profiles')->find($task->subject_id);
        if ($profile === null) {
            return;
        }
        $rating = is_numeric(data_get($result, 'rating.value')) ? round((float) data_get($result, 'rating.value'), 2) : null;
        $count = is_numeric($result['reviews_count'] ?? null) ? (int) $result['reviews_count'] : (is_numeric(data_get($result, 'rating.votes_count')) ? (int) data_get($result, 'rating.votes_count') : null);
        DB::table('review_profiles')->where('id', $profile->id)->update(['rating' => $rating, 'reviews_count' => $count, 'fetched_at' => now(), 'updated_at' => now()]);
        DB::table('review_profile_snapshots')->updateOrInsert(['review_profile_id' => $profile->id, 'observed_on' => now()->toDateString()], ['rating' => $rating, 'reviews_count' => $count, 'created_at' => now(), 'updated_at' => now()]);
        foreach ((array) ($result['items'] ?? []) as $item) {
            if (! is_array($item) || blank($item['review_id'] ?? null)) {
                continue;
            }
            DB::table('review_items')->updateOrInsert(['review_profile_id' => $profile->id, 'review_id' => mb_substr((string) $item['review_id'], 0, 255)], [
                'rating' => is_numeric(data_get($item, 'rating.value')) ? (int) round((float) data_get($item, 'rating.value')) : null,
                'text' => filled($item['review_text'] ?? null) ? mb_substr((string) $item['review_text'], 0, 5000) : null,
                'published_at' => filled($item['timestamp'] ?? null) ? CarbonImmutable::parse((string) $item['timestamp']) : null,
                'owner_answered' => filled($item['owner_answer'] ?? null),
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    public function handleFailure(object $task, string $error): void {}

    /**
     * One row per profile: rating (and change over ~90 days), count, reviews in the last 30 / 90 days, owner
     * response rate, average rating of the last 90 days.
     *
     * @return list<array<string, mixed>>
     */
    public function comparison(Brand $brand): array
    {
        $out = [];
        foreach (DB::table('review_profiles')->where('brand_id', $brand->id)->where('active', true)->orderByDesc('is_own')->orderByDesc('reviews_count')->get() as $profile) {
            $items = DB::table('review_items')->where('review_profile_id', $profile->id);
            $last90 = (clone $items)->where('published_at', '>=', now()->subDays(90));
            $old = DB::table('review_profile_snapshots')->where('review_profile_id', $profile->id)->where('observed_on', '<=', now()->subDays(80)->toDateString())->orderByDesc('observed_on')->first();
            $recent = (clone $items)->orderByDesc('published_at')->limit(50)->get(['owner_answered']);
            $out[] = [
                'id' => (int) $profile->id,
                'title' => (string) $profile->title,
                'is_own' => (bool) $profile->is_own,
                'source' => (string) $profile->source,
                'rating' => $profile->rating !== null ? (float) $profile->rating : null,
                'rating_change' => $old !== null && $old->rating !== null && $profile->rating !== null ? round((float) $profile->rating - (float) $old->rating, 2) : null,
                'reviews_count' => $profile->reviews_count !== null ? (int) $profile->reviews_count : null,
                'last30' => (clone $items)->where('published_at', '>=', now()->subDays(30))->count(),
                'last90' => (clone $last90)->count(),
                'avg90' => ($avg = (clone $last90)->avg('rating')) !== null ? round((float) $avg, 2) : null,
                'response_rate' => $recent->isNotEmpty() ? (int) round($recent->where('owner_answered', true)->count() / $recent->count() * 100) : null,
                'fetched_at' => $profile->fetched_at,
            ];
        }

        return $out;
    }

    /**
     * Words and two-word phrases that recur in negative reviews (rating ≤ negative_max_rating) of the given profile.
     *
     * @return list<array{phrase: string, count: int}>
     */
    public function negativeThemes(int $profileId, int $limit = 12): array
    {
        $texts = DB::table('review_items')->where('review_profile_id', $profileId)->where('rating', '<=', (int) config('moxdop-intel.reviews.negative_max_rating', 2))
            ->whereNotNull('text')->orderByDesc('published_at')->limit(300)->pluck('text');
        $counts = [];
        $display = [];
        foreach ($texts as $text) {
            // Folded key for counting ("süresi" = "suresi"), the first original spelling for display.
            $tokens = [];
            foreach (preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower((string) $text)) ?: [] as $word) {
                $key = SeoText::tokens($word)[0] ?? null;
                if ($key !== null && mb_strlen($key) >= 3) {
                    $tokens[] = ['key' => $key, 'word' => $word];
                }
            }
            $seen = [];
            foreach ($tokens as $i => $token) {
                $seen[$token['key']] = $token['word'];
                if (isset($tokens[$i + 1])) {
                    $seen[$token['key'].' '.$tokens[$i + 1]['key']] = $token['word'].' '.$tokens[$i + 1]['word'];
                }
            }
            foreach ($seen as $key => $shown) {
                $counts[$key] = ($counts[$key] ?? 0) + 1;
                $display[$key] ??= $shown;
            }
        }
        $counts = array_filter($counts, static fn (int $c): bool => $c >= 2);
        // Most frequent first; on a tie the two-word phrase comes before its words.
        uksort($counts, static fn ($a, $b): int => [$counts[$b], substr_count((string) $b, ' ')] <=> [$counts[$a], substr_count((string) $a, ' ')]);
        $out = [];
        $listed = [];
        foreach ($counts as $key => $count) {
            // A word whose every use is inside an already-listed phrase adds nothing.
            if (collect($listed)->contains(fn (array $row): bool => str_contains($row['key'], ' ') && str_contains(' '.$row['key'].' ', ' '.$key.' ') && $row['count'] === $count)) {
                continue;
            }
            $listed[] = ['key' => (string) $key, 'count' => $count];
            $out[] = ['phrase' => $display[$key], 'count' => $count];
            if (count($out) >= $limit) {
                break;
            }
        }

        return $out;
    }
}
