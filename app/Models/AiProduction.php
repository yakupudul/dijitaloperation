<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One archived AI output version (Üretim Arşivi).
 *
 * @property int $id
 * @property string $kind
 * @property string $subject_type
 * @property int $subject_id
 * @property int $version
 * @property array<string, mixed> $content
 * @property string $status
 * @property ?int $rating
 */
class AiProduction extends Model
{
    public const string STATUS_NEW = 'new';

    public const string STATUS_USED = 'used';

    public const string STATUS_PUBLISHED = 'published';

    public const string STATUS_DISCARDED = 'discarded';

    public const array STATUSES = [self::STATUS_NEW, self::STATUS_USED, self::STATUS_PUBLISHED, self::STATUS_DISCARDED];

    protected $fillable = [
        'kind', 'subject_type', 'subject_id', 'brand_id', 'digital_asset_id', 'version', 'title', 'content', 'content_hash',
        'provider', 'model', 'prompt_version', 'status', 'rating', 'note', 'status_changed_by', 'status_changed_at',
    ];

    protected function casts(): array
    {
        return ['content' => 'array', 'rating' => 'integer', 'version' => 'integer', 'status_changed_at' => 'datetime'];
    }

    /** @return BelongsTo<Brand, $this> */
    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }
}
