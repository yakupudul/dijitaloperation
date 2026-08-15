<?php

namespace App\Models;

use App\Enums\PlaybookReferenceKind;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'playbook_revision_id',
    'kind',
    'label',
    'url',
    'route_name',
    'description',
    'position',
])]
class PlaybookReference extends Model
{
    /**
     * @return BelongsTo<PlaybookRevision, $this>
     */
    public function revision(): BelongsTo
    {
        return $this->belongsTo(PlaybookRevision::class, 'playbook_revision_id');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'kind' => PlaybookReferenceKind::class,
            'position' => 'integer',
        ];
    }
}
