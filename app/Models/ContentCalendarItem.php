<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

/** One planned piece of content for a brand (İçerik takvimi). */
class ContentCalendarItem extends Model
{
    public const array CHANNELS = ['gbp_post' => 'İşletme Profili gönderisi', 'blog' => 'Blog yazısı', 'social' => 'Sosyal medya', 'newsletter' => 'E-bülten', 'other' => 'Diğer'];

    public const array STATUSES = ['draft' => 'Taslak', 'approved' => 'Onaylı · zamanı bekliyor', 'published' => 'Yayında', 'failed' => 'Yayınlanamadı', 'skipped' => 'Vazgeçildi'];

    protected $guarded = ['id'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['scheduled_for' => 'datetime', 'published_at' => 'datetime'];
    }

    /** @return BelongsTo<Brand, $this> */
    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }

    /** @return BelongsTo<DigitalAsset, $this> */
    public function digitalAsset(): BelongsTo
    {
        return $this->belongsTo(DigitalAsset::class);
    }

    /** ADR-075: the latest client approval request for this item. @return HasOne<ClientApproval, $this> */
    public function clientApproval(): HasOne
    {
        return $this->hasOne(ClientApproval::class, 'subject_id')->where('subject_type', 'content_calendar_item')
            ->where('status', '!=', 'superseded')->latestOfMany();
    }
}
