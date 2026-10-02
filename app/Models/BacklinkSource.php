<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A potential link source ("Potansiyel kaynaklar"): AI-proposed or manual. Fee is ücretsiz / ücretli only with an
 * evidence URL. Status (exactly five): henüz tespit edilmedi · başvuru / iletişim yapıldı (operator) · kullanıcı
 * eklediğini bildirdi (operator enters the link URL) · sayfada doğrulandı (system found the link) · daha sonra kaldırıldı
 * (a verified link the system no longer finds).
 */
class BacklinkSource extends Model
{
    public const string NONE = 'yok';

    public const string APPLIED = 'basvuru';

    public const string GIVEN = 'verildi';

    public const string VERIFIED = 'dogrulandi';

    public const string REMOVED = 'kaldirildi';

    public const array STATUSES = [self::NONE, self::APPLIED, self::GIVEN, self::VERIFIED, self::REMOVED];

    public const array STATUS_LABELS = [
        self::NONE => 'henüz tespit edilmedi', self::APPLIED => 'başvuru / iletişim yapıldı', self::GIVEN => 'kullanıcı eklediğini bildirdi',
        self::VERIFIED => 'sayfada doğrulandı', self::REMOVED => 'daha sonra kaldırıldı',
    ];

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
