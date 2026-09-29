<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A potential link source ("Potansiyel kaynaklar"): AI-proposed or manual. Fee is ücretsiz / ücretli only with an
 * evidence URL. Status: yok · verildi (operator entered the link URL) · doğrulandı (system found the link on the page).
 */
class BacklinkSource extends Model
{
    public const string NONE = 'yok';

    public const string GIVEN = 'verildi';

    public const string VERIFIED = 'dogrulandi';

    public const array STATUS_LABELS = [self::NONE => 'yok', self::GIVEN => 'verildi', self::VERIFIED => 'doğrulandı'];

    public const array FEE_LABELS = ['ucretsiz' => 'ücretsiz', 'ucretli' => 'ücretli', 'teyit' => 'teyit gerekli'];

    public const array KIND_LABELS = ['dizin' => 'dizin', 'dernek' => 'dernek', 'yerel_haber' => 'yerel haber', 'oda' => 'oda', 'diger' => 'diğer'];

    /** @var list<string> */
    protected $fillable = [
        'brand_id', 'name', 'url', 'domain', 'kind', 'fee', 'fee_evidence_url', 'reason', 'origin', 'status', 'link_url',
        'note', 'verified_at', 'checked_at',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['verified_at' => 'immutable_datetime', 'checked_at' => 'immutable_datetime'];
    }

    /** @return BelongsTo<Brand, $this> */
    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }
}
