<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BrandContextActivity extends Model
{
    public $timestamps = false;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'domain_event_id',
        'brand_id',
        'customer_id',
        'digital_asset_id',
        'actor_user_id',
        'actor_kind',
        'event',
        'subject_type',
        'subject_id',
        'payload',
        'occurred_at',
        'created_at',
    ];

    /**
     * @return BelongsTo<DomainEvent, $this>
     */
    public function domainEvent(): BelongsTo
    {
        return $this->belongsTo(DomainEvent::class, 'domain_event_id');
    }

    /**
     * @return BelongsTo<Brand, $this>
     */
    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }

    /**
     * @return BelongsTo<Customer, $this>
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /**
     * @return BelongsTo<DigitalAsset, $this>
     */
    public function digitalAsset(): BelongsTo
    {
        return $this->belongsTo(DigitalAsset::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'payload' => 'array',
            'occurred_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }
}
