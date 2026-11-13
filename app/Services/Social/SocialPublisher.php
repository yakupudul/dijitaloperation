<?php

namespace App\Services\Social;

use App\Models\SocialAccount;
use App\Models\SocialPost;
use App\Models\SocialPostTarget;
use App\Support\EmergencyStop;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Sends approved social posts at their time (ADR-083). Instagram: image, carousel (2-10), Reels and Story through
 * media containers (a video container is checked again on the next run until Instagram finishes it). Facebook Page:
 * photo, several photos in one post, video and photo story. LinkedIn company page through LinkedInApi once it is
 * connected (a video is checked again until LinkedIn makes it AVAILABLE); without a usable LinkedIn token its row
 * turns "manual" and waits on the screen as "copy and open". A post that failed is retried up to three times when the cause is
 * temporary; otherwise it stays "Yayınlanamadı" with Meta's own message.
 */
class SocialPublisher
{
    /** Frame used as the Reels cover when the post carries no cover image (Instagram `thumb_offset`, ms). */
    public const int IG_HASHTAGS = 5;

    private const REELS_COVER_MS = 5000;

    public function __construct(private readonly SocialGraph $graph, private readonly LinkedInApi $linkedin) {}

    /** Publishes everything due. @return array{published: int, failed: int, processing: int} */
    public function run(): array
    {
        if (EmergencyStop::active()) {
            // Acil durdurma: approved posts keep their place and go out once the stop is lifted.
            return ['published' => 0, 'failed' => 0, 'processing' => 0];
        }
        $lock = Cache::lock('social-publisher', 600);
        if (! $lock->get()) {
            return ['published' => 0, 'failed' => 0, 'processing' => 0];
        }
        $stats = ['published' => 0, 'failed' => 0, 'processing' => 0];
        try {
            SocialPost::query()
                ->where('status', SocialPost::DRAFT)
                ->where('publish_at', '<', now()->subDay())
                ->update(['status' => SocialPost::EXPIRED]);

            $posts = SocialPost::query()
                ->whereIn('status', [SocialPost::APPROVED, SocialPost::PARTIAL])
                ->where('publish_at', '<=', now())
                ->where('publish_at', '>=', now()->subDays(3))
                ->with(['targets.account'])
                ->orderBy('publish_at')
                ->limit(20)
                ->get();

            foreach ($posts as $post) {
                foreach ($post->targets as $target) {
                    if ($target->isFinished() || $target->status === SocialPostTarget::MANUAL) {
                        continue;
                    }
                    if ($target->status === SocialPostTarget::FAILED && $target->attempts >= SocialPostTarget::MAX_ATTEMPTS) {
                        continue;
                    }
                    $result = $this->publishTarget($post, $target);
                    $stats[$result] = ($stats[$result] ?? 0) + 1;
                }
                $this->settle($post->fresh(['targets']));
            }
        } finally {
            $lock->release();
        }

        return $stats;
    }

    /** @return string published | failed | processing | manual | skipped */
    public function publishTarget(SocialPost $post, SocialPostTarget $target): string
    {
        $account = $target->account;
        if (! $account instanceof SocialAccount || ! $account->enabled) {
            $target->update(['status' => SocialPostTarget::SKIPPED, 'error' => 'Hesap kapalı.']);

            return 'failed';
        }
        if ($post->created_by === 'blog' && $account->platform === SocialAccount::INSTAGRAM) {
            // Blog posts planned before the rule changed still carry an Instagram row: it is closed, not sent.
            $target->update(['status' => SocialPostTarget::SKIPPED, 'error' => 'Blog yazıları Instagram’a gönderilmez.']);

            return 'skipped';
        }
        if ($account->isManual()) {
            $target->update(['status' => SocialPostTarget::MANUAL]);

            return 'manual';
        }

        try {
            $outcome = match ($account->platform) {
                SocialAccount::INSTAGRAM => $this->instagram($post, $target, $account),
                SocialAccount::FACEBOOK => $this->facebook($post, $account),
                SocialAccount::LINKEDIN => $this->linkedin->publish($post, $target, $account),
                default => throw new SocialGraphException('Bu kanal desteklenmiyor.'),
            };
        } catch (SocialGraphException $e) {
            $target->update([
                'status' => SocialPostTarget::FAILED,
                'attempts' => $e->isTemporary() ? $target->attempts + 1 : SocialPostTarget::MAX_ATTEMPTS,
                'error' => $e->getMessage(),
            ]);

            return 'failed';
        } catch (Throwable $e) {
            report($e);
            $target->update(['status' => SocialPostTarget::FAILED, 'attempts' => $target->attempts + 1, 'error' => 'Beklenmeyen hata.']);

            return 'failed';
        }

        if ($outcome['status'] === 'processing') {
            $target->update(['status' => SocialPostTarget::PROCESSING, 'container_id' => $outcome['container'], 'error' => null]);

            return 'processing';
        }
        $target->update([
            'status' => SocialPostTarget::PUBLISHED, 'external_id' => $outcome['id'], 'permalink' => $outcome['permalink'] ?? null,
            'published_at' => now(), 'error' => null, 'container_id' => null,
        ]);

        return 'published';
    }

    /** Post status from its channels. */
    public function settle(SocialPost $post): void
    {
        $targets = $post->targets;
        if ($targets->isEmpty()) {
            return;
        }
        $done = $targets->filter(fn (SocialPostTarget $t): bool => $t->isFinished() || $t->status === SocialPostTarget::MANUAL);
        $failedForGood = $targets->filter(fn (SocialPostTarget $t): bool => $t->status === SocialPostTarget::FAILED && $t->attempts >= SocialPostTarget::MAX_ATTEMPTS);
        $status = match (true) {
            $done->count() === $targets->count() => SocialPost::PUBLISHED,
            $done->count() + $failedForGood->count() === $targets->count() && $done->isEmpty() => SocialPost::FAILED,
            $done->isNotEmpty() || $failedForGood->isNotEmpty() => SocialPost::PARTIAL,
            default => $post->status,
        };
        if ($status !== $post->status) {
            $post->update(['status' => $status]);
        }
    }

    /**
     * Instagram takes at most five hashtags (since December 2025); the first five stay, the rest go, so a long tag line
     * never stops a post.
     */
    public static function igCaption(string $caption): string
    {
        $seen = 0;
        $out = (string) preg_replace_callback('/(^|\s)#[\p{L}\p{N}_]+/u', static function (array $m) use (&$seen): string {
            return ++$seen <= self::IG_HASHTAGS ? $m[0] : '';
        }, $caption);

        return $seen <= self::IG_HASHTAGS ? $caption : rtrim((string) preg_replace('/[ \t]+$/mu', '', $out));
    }

    /** @return array{status: string, id?: string, permalink?: ?string, container?: string} */
    private function instagram(SocialPost $post, SocialPostTarget $target, SocialAccount $account): array
    {
        $token = (string) $account->access_token;
        $ig = $account->external_id;
        $media = $post->mediaItems();
        if ($media === []) {
            throw new SocialGraphException('Görsel yok.');
        }

        $container = $target->container_id;
        if ($container === null || $container === '') {
            $caption = $post->kind === 'story' ? null : self::igCaption((string) $post->caption);
            $container = match ($post->kind) {
                'carousel' => $this->igCarousel($ig, $token, $media, $caption),
                // A Reels post may carry an image after the video: it is the cover shown on the profile grid
                // (yakup 2026-11-12: without it Instagram shows the first frame, an empty screen in our videos).
                // Without a cover image the frame at the fifth second is used: our videos open empty and fill in.
                'reels' => $this->igReels($ig, $token, $media, $caption, app(SocialSchedule::class)->isTrial($post)),
                'story' => $this->igCreate($ig, $token, $media[0]['type'] === 'video'
                    ? ['media_type' => 'STORIES', 'video_url' => $media[0]['url']]
                    : ['media_type' => 'STORIES', 'image_url' => $media[0]['url']]),
                default => $media[0]['type'] === 'video'
                    ? $this->igCreate($ig, $token, ['media_type' => 'REELS', 'video_url' => $media[0]['url'], 'caption' => $caption, 'share_to_feed' => 'true'])
                    : $this->igCreate($ig, $token, ['image_url' => $media[0]['url'], 'caption' => $caption]),
            };
        }

        $status = (string) ($this->graph->get($container, $token, ['fields' => 'status_code'])['status_code'] ?? 'FINISHED');
        if ($status === 'ERROR' || $status === 'EXPIRED') {
            $target->update(['container_id' => null]);

            throw new SocialGraphException('Instagram dosyayı işleyemedi (video biçimi ya da süresi uygun değil).');
        }
        if ($status !== 'FINISHED' && $status !== 'PUBLISHED') {
            return ['status' => 'processing', 'container' => $container];
        }

        $published = $this->graph->post($ig.'/media_publish', $token, ['creation_id' => $container]);
        $id = (string) ($published['id'] ?? '');
        $permalink = null;
        try {
            $permalink = $this->graph->get($id, $token, ['fields' => 'permalink'])['permalink'] ?? null;
        } catch (SocialGraphException) {
        }

        return ['status' => 'published', 'id' => $id, 'permalink' => is_string($permalink) ? $permalink : null];
    }

    /**
     * A trial reel (SocialSchedule::isTrial) carries `trial_params`: Instagram shows it to non-followers first and moves
     * it to the profile itself when it does well (SS_PERFORMANCE). An account Instagram has not opened trial reels for
     * refuses the container; the video then goes out as an ordinary reel, so a refusal never costs the day's video.
     *
     * @param  list<array{path: string, type: string, url: string}>  $media
     */
    private function igReels(string $ig, string $token, array $media, ?string $caption, bool $trial): string
    {
        $params = ['media_type' => 'REELS', 'video_url' => $this->firstOf($media, 'video')['url'], 'caption' => $caption, 'share_to_feed' => 'true']
            + (($cover = $this->firstOf($media, 'image', false)) !== null ? ['cover_url' => $cover['url']] : ['thumb_offset' => self::REELS_COVER_MS]);
        if ($trial) {
            try {
                return $this->igCreate($ig, $token, $params + ['trial_params' => (string) json_encode(['graduation_strategy' => 'SS_PERFORMANCE'])]);
            } catch (SocialGraphException $e) {
                if ($e->isTemporary()) {
                    throw $e;
                }
                Log::info('Instagram trial reel refused; publishing as an ordinary reel.', ['error' => $e->getMessage()]);
            }
        }

        return $this->igCreate($ig, $token, $params);
    }

    /** @param  array<string, scalar|null>  $params */
    private function igCreate(string $ig, string $token, array $params): string
    {
        $id = (string) ($this->graph->post($ig.'/media', $token, $params)['id'] ?? '');
        if ($id === '') {
            throw new SocialGraphException('Instagram içerik kabı açılamadı.');
        }

        return $id;
    }

    /** @param  list<array{path: string, type: string, url: string}>  $media */
    private function igCarousel(string $ig, string $token, array $media, ?string $caption): string
    {
        $children = [];
        foreach (array_slice($media, 0, 10) as $item) {
            $children[] = $this->igCreate($ig, $token, $item['type'] === 'video'
                ? ['media_type' => 'VIDEO', 'video_url' => $item['url'], 'is_carousel_item' => 'true']
                : ['image_url' => $item['url'], 'is_carousel_item' => 'true']);
        }
        if (count($children) < 2) {
            throw new SocialGraphException('Kaydırmalı gönderi en az iki görsel ister.');
        }

        return $this->igCreate($ig, $token, ['media_type' => 'CAROUSEL', 'children' => implode(',', $children), 'caption' => $caption]);
    }

    /** @return array{status: string, id: string, permalink: ?string} */
    private function facebook(SocialPost $post, SocialAccount $account): array
    {
        $token = (string) $account->access_token;
        $page = $account->external_id;
        $media = $post->mediaItems();
        $message = (string) $post->caption;
        if ($media === []) {
            throw new SocialGraphException('Görsel yok.');
        }

        if ($post->kind === 'story') {
            if ($media[0]['type'] === 'video') {
                throw new SocialGraphException('Facebook video story desteklenmiyor; yalnız Instagram\'a gider.');
            }
            $photo = (string) ($this->graph->post($page.'/photos', $token, ['url' => $media[0]['url'], 'published' => 'false'])['id'] ?? '');
            $story = $this->graph->post($page.'/photo_stories', $token, ['photo_id' => $photo]);

            return ['status' => 'published', 'id' => (string) ($story['post_id'] ?? $story['id'] ?? $photo), 'permalink' => null];
        }

        $video = $this->firstOf($media, 'video', false);
        if ($post->kind === 'reels' || $video !== null) {
            $item = $video ?? $media[0];
            $result = $this->graph->post($page.'/videos', $token, ['file_url' => $item['url'], 'description' => $message]);
            $id = (string) ($result['id'] ?? '');

            return ['status' => 'published', 'id' => $id, 'permalink' => $id !== '' ? 'https://www.facebook.com/'.$page.'/videos/'.$id : null];
        }

        if (count($media) === 1) {
            $result = $this->graph->post($page.'/photos', $token, ['url' => $media[0]['url'], 'message' => $message]);
            $id = (string) ($result['post_id'] ?? $result['id'] ?? '');

            return ['status' => 'published', 'id' => $id, 'permalink' => $id !== '' ? 'https://www.facebook.com/'.$id : null];
        }

        $form = ['message' => $message];
        foreach (array_slice($media, 0, 10) as $i => $item) {
            $photoId = (string) ($this->graph->post($page.'/photos', $token, ['url' => $item['url'], 'published' => 'false'])['id'] ?? '');
            $form['attached_media['.$i.']'] = json_encode(['media_fbid' => $photoId]);
        }
        $result = $this->graph->post($page.'/feed', $token, $form);
        $id = (string) ($result['id'] ?? '');

        return ['status' => 'published', 'id' => $id, 'permalink' => $id !== '' ? 'https://www.facebook.com/'.$id : null];
    }

    /**
     * @param  list<array{path: string, type: string, url: string}>  $media
     * @return ($required is true ? array{path: string, type: string, url: string} : array{path: string, type: string, url: string}|null)
     */
    private function firstOf(array $media, string $type, bool $required = true): ?array
    {
        foreach ($media as $item) {
            if ($item['type'] === $type) {
                return $item;
            }
        }
        if ($required) {
            throw new SocialGraphException($type === 'video' ? 'Reels için video yok.' : 'Görsel yok.');
        }

        return null;
    }
}
