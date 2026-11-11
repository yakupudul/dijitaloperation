<?php

namespace App\Services\Calendar;

use App\Models\Promise;
use App\Models\Reminder;
use App\Models\SalesLead;
use App\Models\User;
use App\Models\WhatsAppConversation;
use App\Models\WhatsAppMessage;
use App\Support\Roles;
use App\Support\SchemaState;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Söz defteri (Günlük Düzen 4.2). When the operator writes "perşembe gönderirim" or "yarın bakarım" to a customer or a
 * prospect, the sentence is caught by rule (a first person future verb together with a time word, no AI, no cost) and
 * asked once on Takvim and the day's agenda: "işe ekle?". "Ekle" makes it a task on the promised day, "Söz değil"
 * dismisses it. Once enough answers show the rule is right (LEARN answered, nine in ten accepted) a caught promise
 * becomes a task without asking. Read only towards the outside: nothing is sent to anyone.
 */
final class PromiseBook
{
    public const int LEARN = 12;

    private const string TZ = 'Europe/Istanbul';

    /** First person future: "gönderirim", "bakacağım", "iletiyorum" is not a promise so present tense is left out. */
    private const string VERB = '/\b(gönder|ilet|yolla|paylaş|bildir|hazırla|tamamla|bitir|hallet|ayarla|incele|güncelle|düzelt|yaz|çıkar|kur|at|bak|ara|dön|yap|ulaş|geç|gel|ver|kontrol ed|teslim ed|haber ver|bilgi ver|dönüş yap)\p{L}{0,4}?(?:[ıiuü]?r[ıiuü][mz]|[ae]r[ıi][mz]|[ae]c[ae]ğ[ıi][mz]|[ıiuü]c[ae]m|[ae]y[ıi]m|[ae]l[ıi]m)\b/u';

    /** Time words with the day they mean, relative to the day the message was sent. */
    private const array DAYS = ['pazartesi' => 1, 'salı' => 2, 'çarşamba' => 3, 'perşembe' => 4, 'cuma' => 5, 'cumartesi' => 6, 'pazar' => 7];

    /** A live outgoing message: caught when it holds a promise. Returns the record, or null when there is none. */
    public function catch(WhatsAppMessage $message): ?Promise
    {
        if (! SchemaState::hasTable('promises') || $message->direction !== 'outgoing' || $message->is_history || ! is_string($message->body)) {
            return null;
        }
        $sentAt = CarbonImmutable::instance($message->sent_at ?? now())->setTimezone(self::TZ);
        if ($sentAt->lt(now()->subDays(3))) {
            return null;
        }
        $found = self::read($message->body, $sentAt);
        $userId = (int) (User::query()->role(Roles::ADMIN)->where('is_active', true)->orderBy('id')->value('id') ?? 0);
        if ($found === null || $userId === 0 || Promise::query()->where('message_id', $message->id)->exists()) {
            return null;
        }
        $conversation = WhatsAppConversation::query()->find($message->conversation_id);
        $lead = $conversation === null ? null : SalesLead::query()->where('conversation_id', $conversation->id)->orderBy('id')->first();
        $promise = Promise::query()->create([
            'user_id' => $userId, 'message_id' => $message->id, 'conversation_id' => $conversation?->id, 'sales_lead_id' => $lead?->id,
            'who' => Str::limit((string) ($lead?->title() ?: $conversation?->contact_name ?: ''), 150, ''), 'text' => $found['text'], 'due_on' => $found['due']->toDateString(), 'status' => 'asked',
        ]);
        if ($this->learned()) {
            $this->add($promise);
        }

        return $promise;
    }

    /**
     * The promise in a text: its sentence and the day it is due. Null when the text holds no promise.
     *
     * @return array{text: string, due: CarbonImmutable}|null
     */
    public static function read(string $body, CarbonImmutable $sentAt): ?array
    {
        foreach (preg_split('/(?<=[.!?\n])\s+|\n+/u', trim($body)) ?: [] as $sentence) {
            $low = mb_strtolower(strtr($sentence, ['İ' => 'i', 'I' => 'ı']));
            if (str_contains($low, '?') || preg_match(self::VERB, $low) !== 1) {
                continue;
            }
            $due = self::due($low, $sentAt);
            if ($due !== null) {
                return ['text' => Str::limit(trim($sentence), 200), 'due' => $due];
            }
        }

        return null;
    }

    private static function due(string $low, CarbonImmutable $sentAt): ?CarbonImmutable
    {
        $day = $sentAt->startOfDay();
        foreach (self::DAYS as $name => $iso) {
            if (preg_match('/\b'.$name.'\b/u', $low) === 1) {
                $ahead = ($iso - $day->dayOfWeekIso + 7) % 7;

                return $day->addDays($ahead === 0 ? 7 : $ahead);
            }
        }

        return match (true) {
            preg_match('/\b(yarın|yarina|yarına)\b/u', $low) === 1 => $day->addDay(),
            preg_match('/\bhaftaya\b|gelecek hafta|önümüzdeki hafta/u', $low) === 1 => $day->addWeek(),
            preg_match('/hafta sonu/u', $low) === 1 => $day->next(CarbonImmutable::SATURDAY),
            preg_match('/\b(bugün|akşam|akşama|birazdan|gün içinde|öğleden sonra|en kısa)\b/u', $low) === 1 => $day,
            default => null,
        };
    }

    /** @return Collection<int, Promise> the promises that wait for "işe ekle?" */
    public function asked(int $userId): Collection
    {
        return SchemaState::hasTable('promises') ? Promise::query()->where('user_id', $userId)->where('status', 'asked')->orderBy('due_on')->orderBy('id')->limit(20)->get() : collect();
    }

    /** "Ekle": the promise becomes the operator's task on the promised day. */
    public function add(Promise $promise): Reminder
    {
        $lead = $promise->sales_lead_id ? SalesLead::query()->find($promise->sales_lead_id) : null;
        $reminder = Reminder::query()->create([
            'user_id' => $promise->user_id, 'kind' => 'task', 'repeat' => 'none', 'all_day' => true, 'sales_lead_id' => $lead?->id, 'brand_id' => $lead?->brand_id,
            'title' => Str::limit('Söz: '.$promise->text.(filled($promise->who) ? ' ('.$promise->who.')' : ''), 250),
            'remind_at' => CarbonImmutable::parse($promise->due_on->toDateString().' 09:00', self::TZ)->utc(),
            'url' => $promise->conversation_id ? route('operator.inbox', ['kayit' => 'wa-'.$promise->conversation_id]) : null,
        ]);
        $promise->forceFill(['status' => 'added', 'reminder_id' => $reminder->id])->save();

        return $reminder;
    }

    /** "Söz değil": dismissed, and counted so the rule is trusted only when it is right. */
    public function reject(Promise $promise): void
    {
        $promise->forceFill(['status' => 'rejected'])->save();
    }

    /** The last LEARN answers: nine in ten accepted means a caught promise no longer asks. */
    public function learned(): bool
    {
        $last = Promise::query()->whereIn('status', ['added', 'rejected'])->orderByDesc('id')->limit(self::LEARN)->pluck('status');

        return $last->count() >= self::LEARN && $last->filter(fn (string $s): bool => $s === 'added')->count() >= (int) ceil(self::LEARN * 0.9);
    }
}
