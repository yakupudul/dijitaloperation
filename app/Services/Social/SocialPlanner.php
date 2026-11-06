<?php

namespace App\Services\Social;

use App\Models\SocialAccount;
use App\Models\SocialPost;
use App\Models\SocialPostTarget;
use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Creates and changes planned social posts (ADR-083). Media are kept on the public disk because Instagram and Facebook
 * fetch them by URL; images are stored as JPEG (Instagram accepts only JPEG). Channels: every enabled account that
 * fits the kind; LinkedIn only when a LinkedIn text is given and never for a story.
 */
class SocialPlanner
{
    public const string TIMEZONE = 'Europe/Istanbul';

    public const int MAX_IMAGE_BYTES = 8 * 1024 * 1024;

    public const int MAX_VIDEO_BYTES = 100 * 1024 * 1024;

    /**
     * @param  array{kind: string, caption?: ?string, linkedin_text?: ?string, pillar?: ?string, title?: ?string, source_url?: ?string, publish_at: string|CarbonInterface, created_by?: string, platforms?: ?list<string>}  $data
     * @param  list<array{bytes: string, name: string}>  $files
     */
    public function create(array $data, array $files): SocialPost
    {
        $kind = (string) $data['kind'];
        if (! in_array($kind, SocialPost::KINDS, true)) {
            throw new InvalidArgumentException('Geçersiz gönderi türü.');
        }
        if ($files === []) {
            throw new InvalidArgumentException('En az bir görsel ya da video gerekir.');
        }
        if ($kind === 'carousel' && (count($files) < 2 || count($files) > 10)) {
            throw new InvalidArgumentException('Kaydırmalı gönderi 2-10 dosya ister.');
        }
        $publishAt = $data['publish_at'] instanceof CarbonInterface
            ? Carbon::instance($data['publish_at'])
            : Carbon::parse((string) $data['publish_at'], self::TIMEZONE);

        return DB::transaction(function () use ($data, $files, $kind, $publishAt): SocialPost {
            $post = SocialPost::query()->create([
                'brand_id' => SocialAccount::query()->whereNotNull('brand_id')->value('brand_id'),
                'kind' => $kind,
                'pillar' => in_array($data['pillar'] ?? null, SocialPost::PILLARS, true) ? $data['pillar'] : null,
                'title' => isset($data['title']) ? Str::limit(trim((string) $data['title']), 190, '') : null,
                'caption' => $this->clean($data['caption'] ?? null, 2200),
                'linkedin_text' => $this->clean($data['linkedin_text'] ?? null, 3000),
                'source_url' => $data['source_url'] ?? null,
                'publish_at' => $publishAt->utc(),
                'media' => [],
                'status' => SocialPost::DRAFT,
                'created_by' => $data['created_by'] ?? 'operator',
            ]);
            $media = [];
            foreach (array_values($files) as $i => $file) {
                $media[] = $this->storeFile($post, $i, $file['bytes'], $file['name']);
            }
            if ($kind === 'reels' && ! collect($media)->contains(fn (array $m): bool => $m['type'] === 'video')) {
                throw new InvalidArgumentException('Reels için video gerekir.');
            }
            $post->update(['media' => $media]);
            $this->syncTargets($post, $data['platforms'] ?? null);

            return $post->fresh(['targets.account']);
        });
    }

    /** @return array{bytes: string, name: string} */
    public function download(string $url): array
    {
        if (! preg_match('#^https://#i', $url)) {
            throw new InvalidArgumentException('Dosya adresi https olmalı.');
        }
        $response = Http::timeout(60)->get($url);
        if ($response->failed()) {
            throw new InvalidArgumentException('Dosya indirilemedi: '.$url);
        }

        return ['bytes' => $response->body(), 'name' => basename((string) parse_url($url, PHP_URL_PATH)) ?: 'dosya'];
    }

    /** @param  list<string>|null  $platforms */
    public function syncTargets(SocialPost $post, ?array $platforms = null): void
    {
        $accounts = SocialAccount::query()->where('enabled', true)->get()->filter(function (SocialAccount $a) use ($post, $platforms): bool {
            if ($platforms !== null && ! in_array($a->platform, $platforms, true)) {
                return false;
            }
            if ($a->platform === SocialAccount::LINKEDIN) {
                return $post->kind !== 'story' && trim((string) $post->linkedin_text) !== '';
            }

            return true;
        });
        $keep = [];
        foreach ($accounts as $account) {
            $target = SocialPostTarget::query()->firstOrCreate(
                ['social_post_id' => $post->id, 'social_account_id' => $account->id],
                ['status' => SocialPostTarget::PENDING],
            );
            $keep[] = $target->id;
        }
        SocialPostTarget::query()->where('social_post_id', $post->id)->whereNotIn('id', $keep)
            ->whereIn('status', [SocialPostTarget::PENDING, SocialPostTarget::FAILED])->delete();
    }

    /** A channel was connected, added or switched: the posts still to go get its row (or lose it). */
    public function syncUpcoming(): int
    {
        $posts = SocialPost::query()->whereIn('status', [SocialPost::DRAFT, SocialPost::APPROVED])
            ->where('publish_at', '>=', now()->subDay())->get();
        foreach ($posts as $post) {
            $this->syncTargets($post);
        }

        return $posts->count();
    }

    public function approve(SocialPost $post, int $userId): void
    {
        if (! in_array($post->status, [SocialPost::DRAFT, SocialPost::EXPIRED], true)) {
            return;
        }
        $publishAt = $post->publish_at;
        if ($post->status === SocialPost::EXPIRED || $publishAt->isPast()) {
            $publishAt = now()->addMinutes(10);
        }
        $post->update(['status' => SocialPost::APPROVED, 'approved_by' => $userId, 'approved_at' => now(), 'publish_at' => $publishAt]);
    }

    /** Back to draft (only while nothing went out). */
    public function unapprove(SocialPost $post): void
    {
        if ($post->status === SocialPost::APPROVED && ! $post->targets()->whereIn('status', [SocialPostTarget::PUBLISHED, SocialPostTarget::PROCESSING, SocialPostTarget::DONE])->exists()) {
            $post->update(['status' => SocialPost::DRAFT, 'approved_by' => null, 'approved_at' => null]);
        }
    }

    public function skip(SocialPost $post): void
    {
        if ($post->isEditable()) {
            $post->update(['status' => SocialPost::SKIPPED]);
            $post->targets()->whereIn('status', [SocialPostTarget::PENDING, SocialPostTarget::FAILED])->update(['status' => SocialPostTarget::SKIPPED]);
        }
    }

    public function retry(SocialPost $post): void
    {
        $post->targets()->where('status', SocialPostTarget::FAILED)->update(['status' => SocialPostTarget::PENDING, 'attempts' => 0, 'error' => null, 'container_id' => null]);
        if (in_array($post->status, [SocialPost::FAILED, SocialPost::PARTIAL], true)) {
            $post->update(['status' => SocialPost::APPROVED, 'publish_at' => $post->publish_at->isPast() ? now() : $post->publish_at]);
        }
    }

    /** @param  array{caption?: ?string, linkedin_text?: ?string, publish_at?: ?string, title?: ?string}  $data */
    public function edit(SocialPost $post, array $data): void
    {
        if (! $post->isEditable()) {
            throw new InvalidArgumentException('Yayınlanmış gönderi değiştirilemez.');
        }
        $update = [];
        if (array_key_exists('caption', $data)) {
            $update['caption'] = $this->clean($data['caption'], 2200);
        }
        if (array_key_exists('linkedin_text', $data)) {
            $update['linkedin_text'] = $this->clean($data['linkedin_text'], 3000);
        }
        if (array_key_exists('title', $data)) {
            $update['title'] = $data['title'] !== null ? Str::limit(trim($data['title']), 190, '') : null;
        }
        if (filled($data['publish_at'] ?? null)) {
            $update['publish_at'] = Carbon::parse((string) $data['publish_at'], self::TIMEZONE)->utc();
        }
        $post->update($update);
        $this->syncTargets($post->fresh());
    }

    public function markManualDone(SocialPostTarget $target): void
    {
        if (in_array($target->status, [SocialPostTarget::MANUAL, SocialPostTarget::PENDING], true)) {
            $target->update(['status' => SocialPostTarget::DONE, 'published_at' => now()]);
            app(SocialPublisher::class)->settle($target->post()->with('targets')->firstOrFail());
        }
    }

    public function delete(SocialPost $post): void
    {
        if (! in_array($post->status, [SocialPost::DRAFT, SocialPost::SKIPPED, SocialPost::EXPIRED], true)) {
            throw new InvalidArgumentException('Yalnız taslak, atlanan ya da onaylanmamış gönderi silinir.');
        }
        foreach ($post->mediaItems() as $item) {
            Storage::disk('public')->delete($item['path']);
        }
        $post->delete();
    }

    /** @return array{path: string, type: string, width?: int, height?: int} */
    private function storeFile(SocialPost $post, int $index, string $bytes, string $name): array
    {
        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        $head = substr($bytes, 0, 16);
        $isVideo = in_array($ext, ['mp4', 'mov'], true) || str_contains($head, 'ftyp');
        $dir = 'social/'.$post->id;
        if ($isVideo) {
            if (strlen($bytes) > self::MAX_VIDEO_BYTES) {
                throw new InvalidArgumentException('Video 100 MB\'tan büyük.');
            }
            $path = $dir.'/'.($index + 1).'-'.Str::random(6).'.mp4';
            Storage::disk('public')->put($path, $bytes);

            return ['path' => $path, 'type' => 'video'];
        }
        if (strlen($bytes) > self::MAX_IMAGE_BYTES * 3) {
            throw new InvalidArgumentException('Görsel çok büyük.');
        }
        $image = @imagecreatefromstring($bytes);
        if ($image === false) {
            throw new InvalidArgumentException('Dosya '.($index + 1).' görsel ya da mp4 değil.');
        }
        $w = imagesx($image);
        $h = imagesy($image);
        $canvas = imagecreatetruecolor($w, $h);
        imagefill($canvas, 0, 0, imagecolorallocate($canvas, 255, 255, 255));
        imagecopy($canvas, $image, 0, 0, 0, 0, $w, $h);
        ob_start();
        imagejpeg($canvas, null, 92);
        $jpeg = (string) ob_get_clean();
        imagedestroy($image);
        imagedestroy($canvas);
        $path = $dir.'/'.($index + 1).'-'.Str::random(6).'.jpg';
        Storage::disk('public')->put($path, $jpeg);

        return ['path' => $path, 'type' => 'image', 'width' => $w, 'height' => $h];
    }

    private function clean(?string $text, int $max): ?string
    {
        if ($text === null) {
            return null;
        }
        $text = trim(str_replace("\r\n", "\n", $text));

        return $text === '' ? null : mb_substr($text, 0, $max);
    }
}
