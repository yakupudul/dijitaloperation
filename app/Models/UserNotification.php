<?php

namespace App\Models;

use App\Enums\DomainEventSubjectKind;
use App\Enums\NotificationKind;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'domain_event_id',
    'recipient_user_id',
    'notification_kind',
    'subject_kind',
    'subject_id',
    'customer_id',
    'brand_id',
    'presentation',
    'read_at',
    'archived_at',
])]
class UserNotification extends Model
{
    /**
     * @return BelongsTo<DomainEvent, $this>
     */
    public function domainEvent(): BelongsTo
    {
        return $this->belongsTo(DomainEvent::class, 'domain_event_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function recipient(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recipient_user_id');
    }

    /**
     * @return BelongsTo<Customer, $this>
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /**
     * @return BelongsTo<Brand, $this>
     */
    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }

    public function isUnread(): bool
    {
        return $this->read_at === null && $this->archived_at === null;
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'notification_kind' => NotificationKind::class,
            'subject_kind' => DomainEventSubjectKind::class,
            'presentation' => 'array',
            'read_at' => 'datetime',
            'archived_at' => 'datetime',
        ];
    }
}
