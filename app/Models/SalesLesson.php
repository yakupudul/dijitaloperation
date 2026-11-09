<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Satış beyni: one thing the sales work learned (a decision or a method) or a question Claude needs answered. Claude
 * proposes, the operator approves, edits or rejects; only approved lessons and answered questions reach the drafts.
 */
class SalesLesson extends Model
{
    public const array KINDS = ['karar' => 'Karar', 'yontem' => 'Yöntem', 'soru' => 'Soru'];

    public const array STATUSES = ['proposed' => 'Onay bekliyor', 'approved' => 'Onaylı', 'rejected' => 'Reddedildi'];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['decided_at' => 'datetime'];
    }

    /** @return BelongsTo<SalesLead, $this> */
    public function lead(): BelongsTo
    {
        return $this->belongsTo(SalesLead::class, 'lead_id');
    }

    /** @param  Builder<self>  $query */
    public function scopeApproved(Builder $query): Builder
    {
        return $query->where('status', 'approved');
    }

    /** @param  Builder<self>  $query */
    public function scopeProposed(Builder $query): Builder
    {
        return $query->where('status', 'proposed');
    }

    public function kindLabel(): string
    {
        return self::KINDS[$this->kind] ?? $this->kind;
    }
}
