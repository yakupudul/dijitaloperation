<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One approved write to an external system (ADR-064): Google Ads shared negative list additions or a
 * WordPress draft. Holds exactly what was sent and the provider ids needed to undo it.
 */
class ExternalWriteAction extends Model
{
    public const string CHANNEL_GOOGLE_ADS = 'google_ads';

    public const string CHANNEL_WORDPRESS = 'wordpress';

    /** ADR-073: Google Business Profile (review reply, local post); ADR-077: categories and services; ADR-079: description, special hours, website link, photos. */
    public const string CHANNEL_GBP = 'gbp';

    /** ADR-073: reply to a Google review (undo restores the previous reply or deletes it). */
    public const string ACTION_REVIEW_REPLY = 'review_reply';

    /** ADR-073: publish a Business Profile local post (undo deletes it). */
    public const string ACTION_LOCAL_POST = 'local_post';

    /** ADR-077: add Google categories / service items to the profile (additions only; undo removes exactly those). */
    public const string ACTION_PROFILE_UPDATE = 'profile_update';

    /** ADR-079: description, special hours or website link of the profile (undo restores the previous values where unchanged since). */
    public const string ACTION_PROFILE_FIELDS = 'profile_fields';

    /** ADR-079: a photo added to the profile from an https address (undo deletes it). */
    public const string ACTION_MEDIA_UPLOAD = 'media_upload';

    public const string ACTION_NEGATIVE_LIST_ADD = 'negative_list_add';

    public const string ACTION_DRAFT_CREATE = 'draft_create';

    /** ADR-076: an article and its language versions as WordPress drafts (source first, translations linked; undo trashes all). */
    public const string ACTION_ARTICLE_DRAFTS = 'article_drafts';

    /** ADR-068: install one WordPress-offered plugin / theme / core update. Cannot be undone from MoxDOP. */
    public const string ACTION_UPDATE_APPLY = 'update_apply';

    /** ADR-070: approved SEO / technical fixes (a batch of site_fix_items). */
    public const string ACTION_SITE_FIX = 'site_fix';

    /** ADR-070: a new version of an existing page saved as a WordPress draft copy. */
    public const string ACTION_CONTENT_DRAFT = 'content_draft';

    /** ADR-070: the approved draft copy replaces the live page (second approval). */
    public const string ACTION_CONTENT_APPLY = 'content_apply';

    /** 1.4.1: the MoxDOP Connector updates itself from a hash-checked ZIP. Cannot be undone from MoxDOP. */
    public const string ACTION_CONNECTOR_UPDATE = 'connector_update';

    /** 1.8.0: Claude builds a site through the connector (ACF, pages, Elementor, media, menus) while its switch is on; undone from the site's log. */
    public const string ACTION_SITE_BUILD = 'site_build';

    protected $guarded = [];

    /** @return BelongsTo<DigitalAsset, $this> */
    public function digitalAsset(): BelongsTo
    {
        return $this->belongsTo(DigitalAsset::class);
    }

    /** @return BelongsTo<User, $this> */
    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function isUndoable(): bool
    {
        if ($this->action === self::ACTION_SITE_BUILD) {
            return in_array($this->status, ['succeeded', 'partial', 'undo_failed'], true)
                && collect((array) data_get($this->result, 'results', []))->contains(fn (mixed $r): bool => filled(data_get($r, 'change_id')));
        }

        return ! in_array($this->action, [self::ACTION_UPDATE_APPLY, self::ACTION_CONNECTOR_UPDATE], true) && in_array($this->status, ['succeeded', 'partial', 'undo_failed'], true);
    }

    public function statusLabel(): string
    {
        return match ($this->status) {
            'scheduled' => 'Zamanlandı',
            'awaiting_approval' => 'Onay bekliyor',
            'rejected' => 'Reddedildi',
            'cancelled' => 'İptal edildi',
            'queued' => 'Kuyrukta',
            'running' => 'Gönderiliyor',
            'succeeded' => 'Uygulandı',
            'partial' => 'Kısmen uygulandı',
            'failed' => 'Başarısız',
            'undoing' => 'Geri alınıyor',
            'undone' => 'Geri alındı',
            'undo_failed' => 'Geri alma başarısız',
            default => $this->status,
        };
    }

    /** @return array<string, mixed> */
    protected function casts(): array
    {
        return [
            'request_payload' => 'array',
            'result' => 'array',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
            'undone_at' => 'datetime',
        ];
    }
}
