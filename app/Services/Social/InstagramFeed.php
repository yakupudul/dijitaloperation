<?php

namespace App\Services\Social;

use App\Models\SocialAccount;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * Instagram görünümü (yakup 2026-11-09: "instagram hesabındaki yayında olan hiçbir post görünmüyor"): the posts that
 * are live on the account, read from the Graph API (also the ones shared from the phone), kept 10 minutes.
 */
final class InstagramFeed
{
    private const int MINUTES = 10;

    public function __construct(private readonly SocialGraph $graph) {}

    /**
     * @return list<array{id: string, permalink: string, caption: string, type: string, image: string, at: string, likes: int, comments: int}>
     */
    public function posts(SocialAccount $account, bool $fresh = false): array
    {
        $key = 'social:ig-feed:'.$account->id;
        if ($fresh) {
            Cache::forget($key);
        }

        return Cache::remember($key, now()->addMinutes(self::MINUTES), function () use ($account): array {
            try {
                $data = $this->graph->get($account->external_id.'/media', (string) $account->access_token, [
                    'fields' => 'id,permalink,caption,media_type,media_url,thumbnail_url,timestamp,like_count,comments_count',
                    'limit' => 60,
                ]);
            } catch (Throwable $e) {
                report($e);

                return [];
            }
            $out = [];
            foreach ((array) ($data['data'] ?? []) as $m) {
                if (! is_array($m) || ! isset($m['id'])) {
                    continue;
                }
                $type = (string) ($m['media_type'] ?? 'IMAGE');
                $out[] = [
                    'id' => (string) $m['id'], 'permalink' => (string) ($m['permalink'] ?? ''), 'caption' => (string) ($m['caption'] ?? ''),
                    'type' => $type, 'image' => (string) ($type === 'VIDEO' ? ($m['thumbnail_url'] ?? '') : ($m['media_url'] ?? '')),
                    'at' => (string) ($m['timestamp'] ?? ''), 'likes' => (int) ($m['like_count'] ?? 0), 'comments' => (int) ($m['comments_count'] ?? 0),
                ];
            }

            return $out;
        });
    }
}
