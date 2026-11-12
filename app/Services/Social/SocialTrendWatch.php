<?php

namespace App\Services\Social;

use App\Models\AgencySetting;
use App\Models\SocialAccount;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Sosyal › Trendler (yakup 2026-11-13): which formats work on Instagram right now. A list of other professional
 * accounts is read through the official Graph API (`business_discovery`, with our own Instagram account's token);
 * a post that got several times that account's usual likes and comments is a format worth looking at. Nothing is
 * stored but the list of names; a reading is kept half a day and made only when someone asks for it.
 */
class SocialTrendWatch
{
    public const int MAX_ACCOUNTS = 60;

    /** A post counts as a hit from this many times the account's median. */
    public const float HIT = 2.0;

    private const int DAYS = 21;

    private const string CACHE = 'social:trend-watch';

    public function __construct(private readonly SocialGraph $graph) {}

    /** @return list<string> Instagram user names without @ */
    public function accounts(): array
    {
        $stored = AgencySetting::query()->value('social_watchlist');
        $stored = is_string($stored) ? json_decode($stored, true) : $stored;

        return $this->clean(is_array($stored) ? $stored : []);
    }

    /**
     * @param  array<array-key, mixed>  $names  user names, with or without @ or a profile link
     * @return list<string>
     */
    public function save(array $names): array
    {
        $clean = $this->clean($names);
        $setting = AgencySetting::query()->first() ?? new AgencySetting;
        $setting->forceFill(['social_watchlist' => $clean])->save();
        Cache::forget(self::CACHE);

        return $clean;
    }

    /**
     * @param  array<array-key, mixed>  $names
     * @return list<string>
     */
    private function clean(array $names): array
    {
        $out = [];
        foreach ($names as $name) {
            if (! is_string($name)) {
                continue;
            }
            $name = strtolower(trim((string) preg_replace('~^.*instagram\.com/~i', '', trim($name)), "@/ \t\r\n"));
            $name = explode('/', explode('?', $name)[0])[0];
            if (preg_match('/^[a-z0-9._]{1,30}$/', $name) === 1) {
                $out[$name] = true;
            }
        }

        return array_slice(array_keys($out), 0, self::MAX_ACCOUNTS);
    }

    /**
     * @return array{read_at: ?string, hits: list<array{account: string, followers: int, permalink: string, caption: string, type: string, at: string, likes: int, comments: int, times: float}>, accounts: list<array{account: string, followers: int, posts: int, median: int}>, errors: array<string, string>}
     */
    public function reading(bool $fresh = false): array
    {
        if ($fresh) {
            Cache::forget(self::CACHE);
        }
        $cached = Cache::get(self::CACHE);
        if (is_array($cached)) {
            return $cached;
        }
        if (! $fresh) {
            return ['read_at' => null, 'hits' => [], 'accounts' => [], 'errors' => []];
        }
        $reading = $this->read();
        Cache::put(self::CACHE, $reading, now()->addHours(12));

        return $reading;
    }

    /** @return array{read_at: ?string, hits: list<array<string, mixed>>, accounts: list<array<string, mixed>>, errors: array<string, string>} */
    private function read(): array
    {
        $own = SocialAccount::query()->where('platform', SocialAccount::INSTAGRAM)->where('enabled', true)->orderBy('id')->first();
        if ($own === null) {
            return ['read_at' => now()->toIso8601String(), 'hits' => [], 'accounts' => [], 'errors' => ['' => 'Instagram hesabı bağlı değil.']];
        }
        $hits = $accounts = $errors = [];
        $since = now()->subDays(self::DAYS);
        foreach ($this->accounts() as $name) {
            try {
                $data = $this->graph->get($own->external_id, (string) $own->access_token, [
                    'fields' => 'business_discovery.username('.$name.'){username,followers_count,media.limit(30){permalink,caption,media_type,media_product_type,timestamp,like_count,comments_count}}',
                ]);
            } catch (Throwable $e) {
                // A personal or renamed account cannot be read; the row says so and the rest goes on.
                $errors[$name] = mb_substr($e->getMessage(), 0, 160);

                continue;
            }
            $found = (array) ($data['business_discovery'] ?? []);
            $media = array_values(array_filter((array) data_get($found, 'media.data', []), 'is_array'));
            $scores = array_map(fn (array $m): int => (int) ($m['like_count'] ?? 0) + (int) ($m['comments_count'] ?? 0), $media);
            sort($scores);
            $median = $scores === [] ? 0 : (int) $scores[intdiv(count($scores), 2)];
            $accounts[] = ['account' => $name, 'followers' => (int) ($found['followers_count'] ?? 0), 'posts' => count($media), 'median' => $median];
            if ($median < 1 || count($media) < 5) {
                continue;
            }
            foreach ($media as $m) {
                $score = (int) ($m['like_count'] ?? 0) + (int) ($m['comments_count'] ?? 0);
                $at = Carbon::parse((string) ($m['timestamp'] ?? 'now'));
                if ($score / $median < self::HIT || $at->lessThan($since)) {
                    continue;
                }
                $hits[] = [
                    'account' => $name, 'followers' => (int) ($found['followers_count'] ?? 0), 'permalink' => (string) ($m['permalink'] ?? ''),
                    'caption' => mb_substr(trim((string) ($m['caption'] ?? '')), 0, 220),
                    'type' => (string) (($m['media_product_type'] ?? '') === 'REELS' ? 'reels' : strtolower((string) ($m['media_type'] ?? 'image'))),
                    'at' => $at->toIso8601String(), 'likes' => (int) ($m['like_count'] ?? 0), 'comments' => (int) ($m['comments_count'] ?? 0),
                    'times' => round($score / $median, 1),
                ];
            }
        }
        usort($hits, fn (array $a, array $b): int => $b['times'] <=> $a['times']);

        return ['read_at' => now()->toIso8601String(), 'hits' => array_slice($hits, 0, 60), 'accounts' => $accounts, 'errors' => $errors];
    }
}
