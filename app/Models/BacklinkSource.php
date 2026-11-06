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

    /** Bağlantı masası: the operator let this one go (not a fit, refused, too slow). */
    public const string DROPPED = 'vazgecildi';

    public const array STATUSES = [self::NONE, self::APPLIED, self::GIVEN, self::VERIFIED, self::REMOVED, self::DROPPED];

    /** Fırsat motorları (Backlink hizmeti mimarisi, 2026-11-06): where a desk row came from. */
    public const array ENGINES = [
        'geri_kazanim' => 'Geri kazanım', 'kunye' => 'Yerel künye', 'iliski' => 'İlişki', 'anilma' => 'Anılma', 'rakip' => 'Rakip boşluğu',
        'miknatis' => 'Link mıknatısı', 'veri' => 'Veri haberi',
    ];

    /** A desk row with no answer this many days after it was sent gets a follow-up. */
    public const int FOLLOW_UP_DAYS = 7;

    public const array STATUS_LABELS = [
        self::NONE => 'henüz tespit edilmedi', self::APPLIED => 'başvuru / iletişim yapıldı', self::GIVEN => 'kullanıcı eklediğini bildirdi',
        self::VERIFIED => 'sayfada doğrulandı', self::REMOVED => 'daha sonra kaldırıldı', self::DROPPED => 'vazgeçildi',
    ];

    public const array FEE_LABELS = ['ucretsiz' => 'ücretsiz', 'ucretli' => 'ücretli', 'teyit' => 'teyit gerekli'];

    public const array KIND_LABELS = LinkSource::KINDS;

    /** @var list<string> */
    protected $fillable = [
        'brand_id', 'name', 'url', 'domain', 'kind', 'fee', 'fee_evidence_url', 'reason', 'origin', 'status', 'link_url',
        'note', 'verified_at', 'checked_at', 'link_source_id', 'link_target_id', 'engine', 'lane', 'anchor', 'material', 'material_status',
        'material_note', 'sent_at', 'given_at', 'next_step_at', 'paid', 'rel',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['verified_at' => 'immutable_datetime', 'checked_at' => 'immutable_datetime', 'material' => 'array', 'sent_at' => 'immutable_datetime',
            'given_at' => 'immutable_datetime', 'next_step_at' => 'immutable_datetime', 'paid' => 'boolean'];
    }

    /** @return BelongsTo<LinkSource, $this> */
    public function pool(): BelongsTo
    {
        return $this->belongsTo(LinkSource::class, 'link_source_id');
    }

    /** @return BelongsTo<LinkTarget, $this> */
    public function target(): BelongsTo
    {
        return $this->belongsTo(LinkTarget::class, 'link_target_id');
    }

    /**
     * The desk step in plain words: hazırlanıyor → hazır → gönderildi → yanıt bekliyor (no answer after
     * FOLLOW_UP_DAYS) → verildi → doğrulandı → kayboldu.
     */
    public function stepLabel(): string
    {
        return match ($this->status) {
            self::NONE => $this->material_status === 'ready' ? 'hazır' : ($this->material_status === 'failed' ? 'malzeme yazılamadı' : 'hazırlanıyor'),
            self::APPLIED => $this->sent_at !== null && $this->sent_at->lt(now()->subDays(self::FOLLOW_UP_DAYS)) ? 'yanıt bekliyor' : 'gönderildi',
            self::GIVEN => 'verildi, doğrulanacak',
            self::VERIFIED => 'doğrulandı',
            self::REMOVED => 'kayboldu',
            self::DROPPED => 'vazgeçildi',
            default => (string) $this->status,
        };
    }

    public function engineLabel(): string
    {
        return self::ENGINES[$this->engine] ?? (string) $this->engine;
    }

    /** @return BelongsTo<Brand, $this> */
    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }
}
