<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One line of a brand's findings (Marka Düzeni 2.1): what Claude saw with its evidence, what it will do, what it
 * needs from the operator and which kind of approval the work takes. "Çöz" moves it to requested; Claude's result
 * comes back on the same row and stays under "Yapılanlar ve sonuçları" with the effect measured a month later.
 *
 * @property int $id
 * @property int $brand_id
 * @property string $kind
 * @property string $area
 * @property string $title
 * @property string $status
 * @property string $approval
 */
class BrandFinding extends Model
{
    /** finding = something to solve; untouched = "baktım, dokunmuyorum"; unseen = "göremedim"; task = only the operator can do it. */
    public const array KINDS = ['finding' => 'Bulgu', 'untouched' => 'Baktım, dokunmuyorum', 'unseen' => 'Göremedim', 'task' => 'Senin işin'];

    /** What the work takes before it is live. */
    public const array APPROVALS = [
        'none' => 'Onaysız, geri alınır', 'redirect' => 'Yönlendirme onayı', 'publish' => 'Yayın onayı', 'file' => 'Dosya, sen yüklersin',
    ];

    /** open → requested ("Çöz") → waiting (done, the operator's approval is asked) → done; closed = put aside by the operator. */
    public const array STATUSES = ['open' => 'Bekliyor', 'requested' => 'Claude\'da', 'waiting' => 'Onayını bekliyor', 'done' => 'Yapıldı', 'closed' => 'Kapatıldı'];

    public const array AREAS = ['Web sitesi', 'Ölçüm', 'Google Ads', 'Meta reklamları', 'İşletme Profili', 'İçerik', 'Mevzuat', 'Erişim', 'Diğer'];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['saw' => 'encrypted', 'plan' => 'encrypted', 'needs' => 'encrypted', 'result' => 'encrypted', 'effect' => 'encrypted',
            'requested_at' => 'datetime', 'resolved_at' => 'datetime'];
    }

    /** @return BelongsTo<Brand, $this> */
    public function brand(): BelongsTo
    {
        return $this->belongsTo(Brand::class);
    }

    /** @param  Builder<self>  $query findings still to be solved (not yet handed over, or in Claude's hands, or waiting for approval) */
    public function scopeLive(Builder $query): Builder
    {
        return $query->where('kind', 'finding')->whereIn('status', ['open', 'requested', 'waiting']);
    }

    public function approvalLabel(): string
    {
        return self::APPROVALS[$this->approval] ?? self::APPROVALS['none'];
    }

    public function statusLabel(): string
    {
        return self::STATUSES[$this->status] ?? $this->status;
    }
}
