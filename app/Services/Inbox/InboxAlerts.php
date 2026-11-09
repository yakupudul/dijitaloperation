<?php

namespace App\Services\Inbox;

use App\Models\Customer;
use App\Models\SalesLead;
use App\Models\SocialMessage;
use App\Models\SocialThread;
use App\Models\WhatsAppConversation;
use App\Models\WhatsAppMessage;
use App\Services\Assistant\PushNotifier;
use App\Services\Sales\LeadIntake;
use App\Services\Sales\SalesLeads;
use App\Services\WhatsApp\WhatsAppConnection;
use App\Support\WhatsApp\MessageText;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Gelen kutusu → telefon (yakup 2026-11-09): the WhatsApp Business app runs on another phone, so the MoxDOP app is the
 * only thing that tells him a message came. Every live incoming WhatsApp message and Instagram / Facebook direct
 * message from a real person is pushed (history, his own numbers, a lone reaction / sticker / deleted message and
 * opted-out chats are not). The title says who it is (new person and the ad, customer, the prospect's status), the
 * body the first words. Messages of one chat within GROUP_MINUTES update the same notification silently ("3 mesaj").
 * 22:00-08:00 the push comes without sound or vibration and the 08:30 summary lists who wrote. A chat still waiting
 * REMIND_AFTER minutes later in the day is reminded once. Tapping opens the chat in Gelen kutusu.
 */
final class InboxAlerts
{
    public const int GROUP_MINUTES = 5;

    /** A message older than this when it reaches MoxDOP (late webhook, an account's first read) is not pushed. */
    public const int FRESH_MINUTES = 120;

    public const int REMIND_AFTER = 30;

    /** WhatsApp message types that are not a word from the person. */
    public const array SKIP_TYPES = ['reaction', 'sticker', 'revoke', 'edit'];

    /** Sales statuses whose silence is not reminded (they are still pushed when they write). */
    private const array NO_REMIND = ['olumsuz', 'aday_degil'];

    public const string PREFIX = 'inbox:';

    public function __construct(private readonly PushNotifier $push) {}

    /** @return bool true when a push went out */
    public function whatsapp(int $messageId): bool
    {
        $message = WhatsAppMessage::query()->find($messageId);
        if ($message === null || $message->direction !== 'incoming' || $message->is_history || in_array($message->message_type, self::SKIP_TYPES, true)
            || $message->sent_at === null || $message->sent_at->lt(now()->subMinutes(self::FRESH_MINUTES))) {
            return false;
        }
        $conversation = WhatsAppConversation::query()->find($message->conversation_id);
        if ($conversation === null || $conversation->opted_out_at !== null || ! $this->isInbox($conversation)
            || in_array(SalesLeads::phoneKey($conversation->contact_id), SalesLeads::OWN_PHONES, true)) {
            return false;
        }
        $lead = SalesLead::query()->where('conversation_id', $conversation->id)->orderByDesc('id')->first();
        $first = ! WhatsAppMessage::query()->where('conversation_id', $conversation->id)->where('direction', 'incoming')
            ->where('id', '!=', $message->id)->exists();
        $name = $lead?->title() ?? (filled($conversation->contact_name) ? (string) $conversation->contact_name : '+'.$conversation->contact_id);
        $fromAd = is_array($conversation->referral) || $lead?->heard_from === 'meta_ads';
        $title = $this->who($name, $lead, $conversation->customer_id, $first, $fromAd);

        return $this->send('wa-'.$conversation->id, $title, (string) $message->body);
    }

    public function social(int $messageId): bool
    {
        $message = SocialMessage::query()->find($messageId);
        if ($message === null || $message->direction !== 'in' || $message->sent_at === null || $message->sent_at->lt(now()->subMinutes(self::FRESH_MINUTES))) {
            return false;
        }
        $thread = SocialThread::query()->with(['account', 'lead'])->find($message->social_thread_id);
        if ($thread === null || $thread->kind !== SocialThread::DM || $thread->status === SocialThread::IGNORED) {
            return false;
        }
        $first = ! SocialMessage::query()->where('social_thread_id', $thread->id)->where('direction', 'in')->where('id', '!=', $message->id)->exists();
        $name = $thread->lead?->title() ?? (filled($thread->author_name) ? (string) $thread->author_name : ($message->author_name ?: 'Biri'));
        $title = $this->who($name, $thread->lead, $thread->lead?->customer_id, $first, false).' · '.($thread->account?->platformLabel() ?? 'Instagram');

        return $this->send('sosyal-'.$thread->id, $title, (string) $message->body);
    }

    /**
     * Inside the 10-minute Satış round: a chat whose last word is the other person's, pushed and still unanswered
     * REMIND_AFTER minutes later (day time), is reminded once.
     */
    public function remindUnanswered(): int
    {
        if (LeadIntake::quiet()) {
            return 0;
        }
        $rows = DB::table('push_notifications')->where('channel', 'browser')->where('dedupe_key', 'like', self::PREFIX.'%')
            ->where('created_at', '>=', now()->subMinutes(self::FRESH_MINUTES + self::REMIND_AFTER))->orderByDesc('id')->get(['dedupe_key', 'title']);
        $sent = 0;
        foreach ($rows->unique(fn ($row): string => self::chatOf((string) $row->dedupe_key)) as $row) {
            $chat = self::chatOf((string) $row->dedupe_key);
            [$waitingSince, $name, $status] = $this->waiting($chat);
            if ($waitingSince === null || in_array($status, self::NO_REMIND, true)
                || $waitingSince->gt(now()->subMinutes(self::REMIND_AFTER)) || $waitingSince->lt(now()->subMinutes(self::FRESH_MINUTES))) {
                continue;
            }
            $key = 'inbox-remind:'.$chat.':'.$waitingSince->timestamp;
            if (DB::table('push_notifications')->where('dedupe_key', $key)->exists()) {
                continue;
            }
            $minutes = (int) $waitingSince->diffInMinutes(now());
            $sent += $this->push->send($key, $name.' '.$minutes.' dk\'dır cevap bekliyor',
                'Son mesajına henüz dönülmedi.', 'high', $this->url($chat), 24, true) > 0 ? 1 : 0;
        }

        return $sent;
    }

    /** 08:30: who wrote in the quiet hours, in one push (with the night forms' summary). */
    public function nightSummary(): int
    {
        $now = CarbonImmutable::now('Europe/Istanbul');
        $since = $now->subDay()->setTime(LeadIntake::QUIET_FROM, 0)->utc();
        $until = $now->setTime(LeadIntake::QUIET_UNTIL, 0)->utc();
        $rows = DB::table('push_notifications')->where('channel', 'browser')->where('dedupe_key', 'like', self::PREFIX.'%')
            ->whereBetween('created_at', [$since, $until])->orderBy('id')->get(['dedupe_key', 'title']);
        $chats = $rows->unique(fn ($row): string => self::chatOf((string) $row->dedupe_key));
        if ($chats->isEmpty()) {
            return 0;
        }
        $names = $chats->take(5)->map(fn ($row): string => (string) $row->title)->implode(', ');
        $this->push->send('inbox-night:'.$now->toDateString(), 'Gece '.$chats->count().' kişi yazdı',
            $names.($chats->count() > 5 ? ' ve '.($chats->count() - 5).' kişi daha.' : '.'), 'high', route('operator.inbox'), 20, true);

        return $chats->count();
    }

    /** "inbox:wa-12:1762700000:3" → "wa-12" */
    public static function chatOf(string $dedupeKey): string
    {
        return explode(':', substr($dedupeKey, strlen(self::PREFIX)))[0] ?? '';
    }

    private function send(string $chat, string $title, string $body): bool
    {
        $text = Str::limit(trim((string) preg_replace('/\s+/u', ' ', MessageText::clean($body))), 110);
        $prior = DB::table('push_notifications')->where('channel', 'browser')->where('dedupe_key', 'like', self::PREFIX.$chat.':%')
            ->where('created_at', '>=', now()->subMinutes(self::GROUP_MINUTES))->orderByDesc('id')->value('dedupe_key');
        if (is_string($prior)) {
            // The same chat a moment ago: the notification is updated quietly with the count and the newest words.
            [, $start, $count] = array_pad(explode(':', substr($prior, strlen(self::PREFIX))), 3, '1');
            $count = (int) $count + 1;

            return $this->push->browser(self::PREFIX.$chat.':'.$start.':'.$count, $title, $count.' mesaj · son: '.$text, 'low', $this->url($chat)) > 0;
        }

        return $this->push->browser(self::PREFIX.$chat.':'.now()->timestamp.':1', $title, $text, LeadIntake::quiet() ? 'low' : 'high', $this->url($chat)) > 0;
    }

    private function who(string $name, ?SalesLead $lead, mixed $customerId, bool $first, bool $fromAd): string
    {
        $customer = $customerId !== null ? Customer::query()->whereKey($customerId)->value('name') : null;
        if (is_string($customer) && $customer !== '') {
            return 'Müşteri: '.$customer;
        }
        if ($lead !== null && $lead->status() === 'musteri') {
            return 'Müşteri: '.$name;
        }
        if ($first) {
            return 'Yeni kişi'.($fromAd ? ' · Meta reklamı' : '').': '.$name;
        }

        return $lead !== null ? $lead->statusLabel().': '.$name : $name;
    }

    /** @return array{0: ?CarbonImmutable, 1: string, 2: ?string} since when the chat waits for us, its name and status */
    private function waiting(string $chat): array
    {
        [$type, $id] = array_pad(explode('-', $chat, 2), 2, '0');
        if ($type === 'wa') {
            $c = WhatsAppConversation::query()->find((int) $id);
            if ($c === null || $c->last_incoming_at === null || $c->done_at !== null || $c->opted_out_at !== null
                || ($c->snoozed_until !== null && $c->snoozed_until->isFuture())
                || ($c->last_message_at !== null && $c->last_message_at->gt($c->last_incoming_at))) {
                return [null, '', null];
            }
            $lead = SalesLead::query()->where('conversation_id', $c->id)->orderByDesc('id')->first();

            return [CarbonImmutable::instance($c->last_incoming_at), $lead?->title() ?? ((string) $c->contact_name ?: '+'.$c->contact_id), $lead?->status()];
        }
        $t = SocialThread::query()->with('lead')->find((int) $id);
        if ($t === null || $t->last_direction !== 'in' || $t->last_in_at === null || $t->status !== SocialThread::OPEN
            || ($t->snoozed_until !== null && $t->snoozed_until->isFuture())) {
            return [null, '', null];
        }

        return [CarbonImmutable::instance($t->last_in_at), $t->lead?->title() ?? ((string) $t->author_name ?: 'Instagram'), $t->lead?->status()];
    }

    private function url(string $chat): string
    {
        return route('operator.inbox', ['kayit' => $chat]);
    }

    private function isInbox(WhatsAppConversation $conversation): bool
    {
        $id = app(WhatsAppConnection::class)->integration()?->id;

        return $id !== null && (int) $id === (int) $conversation->integration_id;
    }
}
