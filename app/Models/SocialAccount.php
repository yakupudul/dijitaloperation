<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One of the agency's own social accounts (ADR-083): an Instagram professional account, a Facebook Page or a LinkedIn
 * company page. Instagram and Facebook are connected through the "Sosyal medya" Meta login and carry the Page token
 * (encrypted); LinkedIn is added by hand and published by copy until its API access is granted.
 */
class SocialAccount extends Model
{
    public const string INSTAGRAM = 'instagram';

    public const string FACEBOOK = 'facebook';

    public const string LINKEDIN = 'linkedin';

    public const array PLATFORMS = [self::INSTAGRAM, self::FACEBOOK, self::LINKEDIN];

    protected $guarded = [];

    protected $hidden = ['access_token'];

    /** @return HasMany<SocialPostTarget, $this> */
    public function targets(): HasMany
    {
        return $this->hasMany(SocialPostTarget::class);
    }

    public function platformLabel(): string
    {
        return match ($this->platform) {
            self::INSTAGRAM => 'Instagram',
            self::FACEBOOK => 'Facebook',
            self::LINKEDIN => 'LinkedIn',
            default => (string) $this->platform,
        };
    }

    public function displayName(): string
    {
        return $this->username !== null && $this->username !== '' ? '@'.$this->username : (string) ($this->name ?? $this->external_id);
    }

    /** LinkedIn has no API access yet: its posts wait as "copy and open" at their time. */
    public function isManual(): bool
    {
        return $this->platform === self::LINKEDIN || $this->access_token === null || $this->access_token === '';
    }

    public function profileUrl(): ?string
    {
        return match ($this->platform) {
            self::INSTAGRAM => $this->username ? 'https://www.instagram.com/'.$this->username.'/' : null,
            self::FACEBOOK => 'https://www.facebook.com/'.$this->external_id,
            self::LINKEDIN => $this->meta['url'] ?? null,
            default => null,
        };
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['access_token' => 'encrypted', 'meta' => 'array', 'enabled' => 'boolean', 'connected_at' => 'datetime'];
    }
}
