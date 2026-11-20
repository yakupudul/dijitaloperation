<?php

namespace App\Panel\Models;

use App\Panel\Support\BelongsToBrand;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A brand's request to the agency: a question, a problem, a wish, a connection to set up. */
class PanelTicket extends Model
{
    use BelongsToBrand;

    public const string OPEN = 'open';

    public const string ANSWERED = 'answered';

    public const string SOLVED = 'solved';

    public const array STATUSES = [self::OPEN => 'Açık', self::ANSWERED => 'Cevaplandı', self::SOLVED => 'Çözüldü'];

    public const array CATEGORIES = ['question' => 'Soru', 'problem' => 'Sorun', 'wish' => 'İstek', 'connection' => 'Bağlantı isteği'];

    protected $fillable = ['panel_user_id', 'subject', 'category', 'status', 'last_message_at'];

    protected function casts(): array
    {
        return ['last_message_at' => 'datetime'];
    }

    public function messages(): HasMany
    {
        return $this->hasMany(PanelTicketMessage::class, 'ticket_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(PanelUser::class, 'panel_user_id');
    }

    public static function tone(string $status): string
    {
        return [self::OPEN => 'warn', self::ANSWERED => 'info', self::SOLVED => 'ok'][$status] ?? 'info';
    }
}
