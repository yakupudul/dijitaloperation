<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One of the agency's own social accounts (ADR-083): an Instagram professional account, a Facebook Page or a LinkedIn
 * company page. Instagram and Facebook are connected through the "Sosyal medya" Meta login and carry the Page token
 * (encrypted). LinkedIn is published through its Community Management API once "LinkedIn'i bağla" stored a member token
 * of a page admin (60 days, renewed with the refresh token); without a usable token its posts wait as "copy and open".
 */
class SocialAccount extends Model
{
    public const string INSTAGRAM = 'instagram';

    public const string FACEBOOK = 'facebook';

    public const string LINKEDIN = 'linkedin';

    public const array PLATFORMS = [self::INSTAGRAM, self::FACEBOOK, self::LINKEDIN];

    protected $guarded = [];

    protected $hidden = ['access_token', 'refresh_token'];

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

    /** No usable token: its posts wait as "copy and open" at their time. */
    public function isManual(): bool
    {
        if ($this->access_token === null || $this->access_token === '') {
            return true;
        }
        if ($this->platform === self::LINKEDIN) {
            // An ended LinkedIn token without a refresh token can no longer publish; LinkedIn must be connected again.
            return ($this->meta['org_urn'] ?? '') === ''
                || ($this->token_expires_at !== null && $this->token_expires_at->isPast() && ($this->refresh_token === null || $this->refresh_token === ''));
        }

        return false;
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
        return ['access_token' => 'encrypted', 'refresh_token' => 'encrypted', 'token_expires_at' => 'datetime', 'meta' => 'array', 'enabled' => 'boolean', 'connected_at' => 'datetime'];
    }
}
