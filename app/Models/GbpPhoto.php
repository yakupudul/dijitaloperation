<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A photo MoxDOP added to a Business Profile (ADR-079): from the brand's site or uploaded by the operator, sent by the
 * Admin through the media write (PhotoPlan). The source hash keeps one photo from going to the same profile twice.
 */
class GbpPhoto extends Model
{
    public const string SENDING = 'sending';

    public const string UPLOADED = 'uploaded';

    public const string FAILED = 'failed';

    public const string REMOVED = 'removed';

    protected $guarded = [];

    /** @return BelongsTo<DigitalAsset, $this> */
    public function digitalAsset(): BelongsTo
    {
        return $this->belongsTo(DigitalAsset::class);
    }

    /** @return BelongsTo<ExternalWriteAction, $this> */
    public function writeAction(): BelongsTo
    {
        return $this->belongsTo(ExternalWriteAction::class, 'external_write_action_id');
    }
}
