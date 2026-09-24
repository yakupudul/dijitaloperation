<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A domain / hosting / SSL / other renewal with its expiry, cost, what the customer is charged and the
 * collection state (not billed → billed → paid).
 */
class AssetRenewal extends Model
{
    public const array KINDS = ['domain' => 'Alan adı', 'hosting' => 'Hosting', 'ssl' => 'SSL', 'other' => 'Diğer'];

    public const array COLLECTION = ['not_billed' => 'Faturalanmadı', 'billed' => 'Faturalandı', 'paid' => 'Ödendi', 'not_charged' => 'Ücret alınmıyor'];

    protected $fillable = [
        'brand_id', 'digital_asset_id', 'kind', 'label', 'provider', 'expires_on', 'expires_source', 'auto_renew',
        'cost_amount', 'charge_amount', 'currency', 'collection_status', 'notes', 'last_checked_at',
    ];

    protected function casts(): array
    {
        return ['expires_on' => 'date', 'auto_renew' => 'boolean', 'cost_amount' => 'float', 'charge_amount' => 'float', 'last_checked_at' => 'datetime'];
    }

    /** @return BelongsTo<Brand, $this> */
    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }

    public function daysLeft(): ?int
    {
        return $this->expires_on !== null ? (int) now()->startOfDay()->diffInDays($this->expires_on, false) : null;
    }
}
