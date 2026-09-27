<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One confirmed yetki devri (ownership transfer) of an external account (resource) or a digital asset.
 * `snapshot` keeps the names at the time of the transfer (subject, customers, brands, assets).
 */
class OwnershipTransfer extends Model
{
    public const string SUBJECT_RESOURCE = 'resource';

    public const string SUBJECT_ASSET = 'asset';

    public const ?string UPDATED_AT = null;

    protected $guarded = [];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'snapshot' => 'array',
            'created_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function transferredBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'transferred_by');
    }

    /**
     * Transfers that moved an account into or out of the asset, or moved the asset itself.
     *
     * @param  Builder<OwnershipTransfer>  $query
     * @return Builder<OwnershipTransfer>
     */
    public function scopeTouchingAsset(Builder $query, int $assetId): Builder
    {
        return $query->where(fn (Builder $q): Builder => $q
            ->where('from_asset_id', $assetId)
            ->orWhere('to_asset_id', $assetId)
            ->orWhere(fn (Builder $asset): Builder => $asset->where('subject_type', self::SUBJECT_ASSET)->where('subject_id', $assetId)));
    }

    /** One Turkish line for the "Devir geçmişi" list. */
    public function summary(): string
    {
        $s = is_array($this->snapshot) ? $this->snapshot : [];
        $from = collect([$s['from_customer'] ?? null, $s['from_brand'] ?? null, $s['from_asset'] ?? null])->filter()->implode(' › ');
        $to = collect([$s['to_customer'] ?? null, $s['to_brand'] ?? null, $s['to_asset'] ?? null])->filter()->implode(' › ');

        return sprintf(
            '%s: %s → %s',
            (string) ($s['subject'] ?? ($this->subject_type === self::SUBJECT_ASSET ? 'Varlık' : 'Hesap')),
            $from !== '' ? $from : 'markasız',
            $to !== '' ? $to : '—',
        );
    }

    /** What happened to the account mapping (sector / services) on this transfer, in one Turkish line; null = nothing. */
    public function mappingSummary(): ?string
    {
        $entries = collect(is_array($this->snapshot) ? ($this->snapshot['mapping'] ?? []) : []);
        $reset = $entries->where('action', 'reset')->count();
        $rescoped = $entries->where('action', 'rescoped')->count();
        if ($reset > 0) {
            return sprintf('%d hesabın sektör / hizmet eşlemesi sıfırlandı; yeni marka için yeniden eşlenecek (Hizmet Beyni › Hesap eşleme).', $reset);
        }
        if ($rescoped > 0) {
            return 'Sektör / hizmet eşlemesi korundu (aynı müşteri); bekleyen eşleme önerileri yeni markaya taşındı.';
        }

        return null;
    }
}
