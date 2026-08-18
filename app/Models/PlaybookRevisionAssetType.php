<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'playbook_revision_id',
    'asset_type',
])]
class PlaybookRevisionAssetType extends Model
{
    /**
     * @return BelongsTo<PlaybookRevision, $this>
     */
    public function revision(): BelongsTo
    {
        return $this->belongsTo(PlaybookRevision::class, 'playbook_revision_id');
    }
}
