<?php

namespace App\Models;

use App\Enums\PlaybookStatus;
use Database\Factories\PlaybookFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'stable_key',
    'status',
    'current_revision_id',
    'created_by',
])]
class Playbook extends Model
{
    /** @use HasFactory<PlaybookFactory> */
    use HasFactory;

    /**
     * @return HasMany<PlaybookRevision, $this>
     */
    public function revisions(): HasMany
    {
        return $this->hasMany(PlaybookRevision::class);
    }

    /**
     * @return BelongsTo<PlaybookRevision, $this>
     */
    public function currentRevision(): BelongsTo
    {
        return $this->belongsTo(PlaybookRevision::class, 'current_revision_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => PlaybookStatus::class,
        ];
    }
}
