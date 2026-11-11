<?php

namespace App\Models;

use App\Services\Calendar\PromiseBook;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class WhatsAppMessage extends Model
{
    protected $table = 'whatsapp_messages';

    protected $guarded = ['id'];

    /** Types WhatsApp hands over without content: nothing to read, so nothing to answer from the panel. */
    public const array UNREADABLE_TYPES = ['errors', 'unsupported'];

    /**
     * Live (not imported) incoming messages that can be read and came after our last outgoing message: the only ones
     * that make a chat owe an answer. A message WhatsApp did not share ("Okunamayan mesaj") is seen on the phone.
     *
     * @param  Builder<self>  $query
     */
    public function scopeOwingAnswer(Builder $query): void
    {
        $query->where('direction', 'incoming')->where('is_history', false)
            ->where(fn (Builder $q) => $q->whereNull('message_type')->orWhereNotIn('message_type', self::UNREADABLE_TYPES))
            ->whereRaw('sent_at >= coalesce((select max(o.sent_at) from whatsapp_messages o where o.conversation_id = whatsapp_messages.conversation_id and o.direction = ?), ?)', ['outgoing', '1970-01-01 00:00:00']);
    }

    /** Söz defteri (Günlük Düzen 4.2): a live outgoing message is read once for a promise; a failure never blocks the message. */
    protected static function booted(): void
    {
        static::created(function (self $message): void {
            if ($message->direction === 'outgoing' && ! $message->is_history) {
                DB::afterCommit(fn () => rescue(fn () => app(PromiseBook::class)->catch($message), report: false));
            }
        });
    }

    protected function casts(): array
    {
        return ['body' => 'encrypted', 'sent_at' => 'datetime', 'is_history' => 'boolean'];
    }
}
