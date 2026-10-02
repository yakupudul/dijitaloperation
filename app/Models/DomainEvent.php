<?php

namespace App\Models;

use App\Enums\DomainEventActorKind;
use App\Enums\DomainEventSubjectKind;
use App\Enums\DomainEventType;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable([
    'event_type',
    'idempotency_key',
    'actor_kind',
    'actor_user_id',
    'customer_id',
    'brand_id',
    'digital_asset_id',
    'subject_kind',
    'subject_id',
    'payload',
    'correlation_id',
    'causation_event_id',
    'occurred_at',
    'projection_status',
])]
class DomainEvent extends Model
{
    /**
     * @return BelongsTo<User, $this>
     */
    public function actorUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
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

    /**
     * @return BelongsTo<DigitalAsset, $this>
     */
    public function digitalAsset(): BelongsTo
    {
        return $this->belongsTo(DigitalAsset::class);
    }

    /**
     * @return HasOne<BrandContextActivity, $this>
     */
    public function activity(): HasOne
    {
        return $this->hasOne(BrandContextActivity::class, 'domain_event_id');
    }

    /**
     * @return HasMany<UserNotification, $this>
     */
    public function notifications(): HasMany
    {
        return $this->hasMany(UserNotification::class, 'domain_event_id');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'event_type' => DomainEventType::class,
            'actor_kind' => DomainEventActorKind::class,
            'subject_kind' => DomainEventSubjectKind::class,
            'payload' => 'array',
            'occurred_at' => 'datetime',
        ];
    }
}
