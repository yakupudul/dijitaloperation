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

    public const string ACTION_NEGATIVE_LIST_ADD = 'negative_list_add';

    public const string ACTION_DRAFT_CREATE = 'draft_create';

    /** ADR-068: install one WordPress-offered plugin / theme / core update. Cannot be undone from MoxDOP. */
    public const string ACTION_UPDATE_APPLY = 'update_apply';

    /** ADR-070: approved SEO / technical fixes (a batch of site_fix_items). */
    public const string ACTION_SITE_FIX = 'site_fix';

    /** ADR-070: a new version of an existing page saved as a WordPress draft copy. */
    public const string ACTION_CONTENT_DRAFT = 'content_draft';

    /** ADR-070: the approved draft copy replaces the live page (second approval). */
    public const string ACTION_CONTENT_APPLY = 'content_apply';

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
        return $this->action !== self::ACTION_UPDATE_APPLY && in_array($this->status, ['succeeded', 'partial', 'undo_failed'], true);
    }

    public function statusLabel(): string
    {
        return match ($this->status) {
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
