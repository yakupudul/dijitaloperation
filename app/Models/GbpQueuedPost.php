<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One planned Business Profile post of a location (ADR-078): written from a page of the brand's site from one angle,
 * approved in bulk by the Admin, published on `publish_on` (a Y-m-d string, Europe/Istanbul) through the ADR-073 write
 * (GbpPostQueue).
 */
class GbpQueuedPost extends Model
{
    public const string DRAFT = 'draft';

    public const string APPROVED = 'approved';

    public const string PUBLISHED = 'published';

    public const string SKIPPED = 'skipped';

    public const string FAILED = 'failed';

    /** Its day passed without approval. */
    public const string EXPIRED = 'expired';

    protected $table = 'gbp_post_queue';

    protected $guarded = [];

    /** @return BelongsTo<DigitalAsset, $this> */
    public function digitalAsset(): BelongsTo
    {
        return $this->belongsTo(DigitalAsset::class);
    }

    /** @return BelongsTo<Page, $this> */
    public function page(): BelongsTo
    {
        return $this->belongsTo(Page::class);
    }

    /** @return BelongsTo<ExternalWriteAction, $this> */
    public function writeAction(): BelongsTo
    {
        return $this->belongsTo(ExternalWriteAction::class, 'external_write_action_id');
    }

    public function statusLabel(): string
    {
        return match ($this->status) {
            self::DRAFT => 'Onay bekliyor',
            self::APPROVED => 'Onaylandı',
            self::PUBLISHED => 'Yayınlandı',
            self::SKIPPED => 'Atlandı',
            self::FAILED => 'Yayınlanamadı',
            self::EXPIRED => 'Onaylanmadı',
            default => $this->status,
        };
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['approved_at' => 'datetime'];
    }
}
