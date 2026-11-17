<?php

namespace App\Services\Social;

use App\Models\SocialPost;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * Small cover pictures for the Sosyal lists (yakup 2026-11-17: the page loaded 71 videos at once). A list, a calendar
 * cell or a grid tile shows one light JPG (320 px wide) instead of the full image or the video; the video itself is
 * loaded only when the post is opened. The cover is the post's first image, for Reels the cover image that follows the
 * video, otherwise a frame of the video when the server can cut one.
 */
final class SocialThumbs
{
    public const int WIDTH = 320;

    /** Covers made in one request at most; the rest show their own picture and get their cover on a later load. */
    public const int PER_REQUEST = 16;

    private int $made = 0;

    private ?bool $ffmpeg = null;

    /** @return array{url: ?string, video: bool, many: bool} `url` null = no picture could be made (a video without a cover) */
    public function cover(SocialPost $post): array
    {
        $media = $post->mediaItems();
        $first = $media[0] ?? null;
        $video = ($first['type'] ?? '') === 'video';
        $image = collect($media)->firstWhere('type', 'image');
        $source = $post->kind === 'reels' ? ($image ?? $first) : $first;
        if ($source === null) {
            return ['url' => null, 'video' => false, 'many' => false];
        }

        return ['url' => $this->url($post, $source), 'video' => $video, 'many' => $post->kind === 'carousel' && count($media) > 1];
    }

    /** @param  array{path: string, type: string, url: string}  $item */
    private function url(SocialPost $post, array $item): ?string
    {
        $disk = Storage::disk('public');
        $thumb = 'social/thumbs/'.$post->id.'-'.substr(sha1($item['path']), 0, 10).'.jpg';
        if ($disk->exists($thumb)) {
            return $disk->url($thumb);
        }
        $fallback = $item['type'] === 'image' ? $item['url'] : null;
        if ($this->made >= self::PER_REQUEST || ! $disk->exists($item['path'])) {
            return $fallback;
        }
        $this->made++;
        try {
            $bytes = $item['type'] === 'image' ? $this->shrink((string) $disk->get($item['path'])) : $this->frame($disk->path($item['path']));
            if ($bytes === null) {
                return $fallback;
            }
            $disk->put($thumb, $bytes);

            return $disk->url($thumb);
        } catch (Throwable) {
            return $fallback;
        }
    }

    private function shrink(string $bytes): ?string
    {
        $image = @imagecreatefromstring($bytes);
        if ($image === false) {
            return null;
        }
        $small = imagesx($image) > self::WIDTH ? imagescale($image, self::WIDTH) : $image;
        if ($small === false) {
            return null;
        }
        ob_start();
        imagejpeg($small, null, 78);

        return (string) ob_get_clean();
    }

    /** A frame of the video near its fifth second (our videos open empty and fill in); null when ffmpeg is missing. */
    private function frame(string $path): ?string
    {
        if ($this->ffmpeg === null) {
            try {
                $this->ffmpeg = (new Process(['ffmpeg', '-version']))->run() === 0;
            } catch (Throwable) {
                $this->ffmpeg = false;
            }
        }
        if (! $this->ffmpeg) {
            return null;
        }
        $out = tempnam(sys_get_temp_dir(), 'thumb').'.jpg';
        foreach (['5', '0.5'] as $second) {
            $process = new Process(['ffmpeg', '-y', '-loglevel', 'error', '-ss', $second, '-i', $path, '-frames:v', '1', '-vf', 'scale='.self::WIDTH.':-2', $out]);
            $process->setTimeout(20);
            if ($process->run() === 0 && is_file($out) && filesize($out) > 0) {
                $bytes = (string) file_get_contents($out);
                @unlink($out);

                return $bytes;
            }
        }
        @unlink($out);

        return null;
    }
}
