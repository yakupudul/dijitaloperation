<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\URL;

/**
 * ADR-075: one request for the client's approval of planned content, answered through a signed link.
 * The operator still approves publishing; the client's answer is input, not a trigger.
 */
class ClientApproval extends Model
{
    public const array STATUSES = ['pending' => 'Müşteri yanıtı bekleniyor', 'approved' => 'Müşteri onayladı', 'changes_requested' => 'Müşteri değişiklik istedi'];

    public const int VALID_DAYS = 14;

    protected $guarded = ['id'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['responded_at' => 'datetime', 'acknowledged_at' => 'datetime', 'expires_at' => 'datetime'];
    }

    /** @return BelongsTo<Brand, $this> */
    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }

    public function isOpen(): bool
    {
        return $this->status === 'pending' && $this->expires_at->isFuture();
    }

    public function link(): string
    {
        return URL::temporarySignedRoute('client-approval.show', $this->expires_at, ['approval' => $this->id]);
    }

    public function respondUrl(): string
    {
        return URL::temporarySignedRoute('client-approval.respond', $this->expires_at, ['approval' => $this->id]);
    }

    public static function requestFor(ContentCalendarItem $item, ?User $by): self
    {
        static::query()->where('subject_type', 'content_calendar_item')->where('subject_id', $item->id)->where('status', 'pending')
            ->update(['status' => 'superseded', 'updated_at' => now()]);

        return static::query()->create([
            'brand_id' => $item->brand_id, 'subject_type' => 'content_calendar_item', 'subject_id' => $item->id,
            'title' => $item->title, 'body' => $item->body, 'expires_at' => now()->addDays(self::VALID_DAYS)->startOfMinute(),
            'created_by' => $by?->id,
        ]);
    }
}
