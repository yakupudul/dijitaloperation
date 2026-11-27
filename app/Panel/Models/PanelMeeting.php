<?php

namespace App\Panel\Models;

use App\Panel\Support\BelongsToBrand;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A meeting the brand asked for; the agency sets the day and the link. */
class PanelMeeting extends Model
{
    use BelongsToBrand;

    public const string REQUESTED = 'requested';

    public const string PLANNED = 'planned';

    public const string DONE = 'done';

    public const string CANCELLED = 'cancelled';

    public const array STATUSES = [self::REQUESTED => 'Gün bekleniyor', self::PLANNED => 'Planlandı', self::DONE => 'Yapıldı', self::CANCELLED => 'İptal edildi'];

    protected $fillable = ['panel_user_id', 'topic', 'preferred', 'note', 'status', 'starts_at', 'meet_url', 'summary'];

    protected function casts(): array
    {
        return ['starts_at' => 'datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(PanelUser::class, 'panel_user_id');
    }
}
