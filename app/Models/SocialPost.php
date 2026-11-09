<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Storage;

/**
 * One planned social post (ADR-083): the same visual and text go to several channels (rows in social_post_targets).
 * Nothing is published before the Admin approves it; at `publish_at` the publisher sends every pending channel.
 */
class SocialPost extends Model
{
    public const string DRAFT = 'draft';

    public const string APPROVED = 'approved';

    public const string PUBLISHED = 'published';

    /** Some channels went out, some failed. */
    public const string PARTIAL = 'partial';

    public const string FAILED = 'failed';

    public const string SKIPPED = 'skipped';

    /** Its time passed more than a day ago without approval. */
    public const string EXPIRED = 'expired';

    public const array KINDS = ['image', 'carousel', 'reels', 'story'];

    public const array PILLARS = ['rehber', 'vaka', 'gundem', 'anlatim', 'guven'];

    protected $guarded = [];

    /** @return HasMany<SocialPostTarget, $this> */
    public function targets(): HasMany
    {
        return $this->hasMany(SocialPostTarget::class);
    }

    public function statusLabel(): string
    {
        return match ($this->status) {
            self::DRAFT => 'Onay bekliyor',
            self::APPROVED => 'Onaylandı',
            self::PUBLISHED => 'Yayınlandı',
            self::PARTIAL => 'Kısmen yayınlandı',
            self::FAILED => 'Yayınlanamadı',
            self::SKIPPED => 'Atlandı',
            self::EXPIRED => 'Onaylanmadı',
            default => (string) $this->status,
        };
    }

    public function kindLabel(): string
    {
        return match ($this->kind) {
            'image' => 'Gönderi',
            'carousel' => 'Kaydırmalı gönderi',
            'reels' => 'Reels',
            'story' => 'Story',
            default => (string) $this->kind,
        };
    }

    public function pillarLabel(): ?string
    {
        return match ($this->pillar) {
            'rehber' => 'Rehber',
            'vaka' => 'Vaka',
            'gundem' => 'Gündem',
            'anlatim' => 'Yakup anlatıyor',
            'guven' => 'Güven',
            default => null,
        };
    }

    /** @return list<array{path: string, type: string, url: string}> */
    public function mediaItems(): array
    {
        $out = [];
        foreach ((array) $this->media as $item) {
            if (! is_array($item) || ! is_string($item['path'] ?? null)) {
                continue;
            }
            $out[] = ['path' => $item['path'], 'type' => (string) ($item['type'] ?? 'image'), 'url' => Storage::disk('public')->url($item['path'])];
        }

        return $out;
    }

    public function isEditable(): bool
    {
        return in_array($this->status, [self::DRAFT, self::APPROVED], true);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['media' => 'array', 'publish_at' => 'datetime', 'approved_at' => 'datetime', 'removed_at' => 'datetime'];
    }
}
