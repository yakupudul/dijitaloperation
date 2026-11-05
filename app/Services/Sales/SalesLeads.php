<?php

namespace App\Services\Sales;

use App\Enums\CustomerStatus;
use App\Enums\CustomerType;
use App\Models\Brand;
use App\Models\Customer;
use App\Models\Reminder;
use App\Models\SalesLead;
use App\Models\SalesLeadEvent;
use App\Models\User;
use App\Models\WhatsAppConversation;
use App\Models\WhatsAppMessage;
use App\Services\Assistant\WhatsAppContactLinker;
use App\Services\WhatsApp\Backup\WhatsAppBackupImporter;
use App\Services\WhatsApp\WhatsAppConnection;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * The prospect cards (Satış): where they come from, what happened, what is next. Rules: a card always has a next step
 * with a date until it is won or lost; a WhatsApp chat that is not a customer's becomes a card by itself; a won card
 * opens the brand. MoxDOP never sends a message — the operator does, from the phone.
 */
final class SalesLeads
{
    /** The operator's own numbers never become leads (the backup also holds chats with the business itself). */
    private const array OWN_PHONES = ['5388610288', '5319527518'];

    public static function boot(): void
    {
        WhatsAppConversation::created(static function (WhatsAppConversation $conversation): void {
            try {
                app(self::class)->fromConversation($conversation);
            } catch (Throwable $exception) {
                report($exception);
            }
        });
        WhatsAppConversation::updated(static function (WhatsAppConversation $conversation): void {
            if (! $conversation->wasChanged(['last_message_at', 'last_incoming_at', 'customer_id', 'contact_name'])) {
                return;
            }
            try {
                app(self::class)->refreshFromConversation($conversation);
            } catch (Throwable $exception) {
                report($exception);
            }
        });
    }

    /** Last ten digits of a phone, or null when it is not a phone (a hidden "lid" contact, a name). */
    public static function phoneKey(?string $phone): ?string
    {
        return WhatsAppContactLinker::key($phone);
    }

    private static function normalise(string $text): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }

    public static function host(?string $url): ?string
    {
        $url = trim((string) $url);
        if ($url === '') {
            return null;
        }
        if (! preg_match('#^https?://#i', $url)) {
            $url = 'https://'.$url;
        }
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        $host = preg_replace('/^www\./', '', $host) ?? $host;

        return $host !== '' && str_contains($host, '.') ? $host : null;
    }

    /** "Hızlı ekle": a clinic, a phone and a source are enough; the first message is due today. */
    public function quickAdd(array $input, ?User $user = null): SalesLead
    {
        $data = collect($input)->map(fn ($value) => is_string($value) ? trim($value) : $value);
        $phone = (string) $data->get('phone', '');
        $website = (string) $data->get('website', '');
        if ($data->get('name', '') === '' && $data->get('clinic', '') === '' && $phone === '' && $website === '' && self::email((string) $data->get('email', '')) === null) {
            throw ValidationException::withMessages(['lead' => 'Bir ad, klinik, telefon ya da site yazın.']);
        }
        $existing = $this->match(self::phoneKey($phone), self::host($website), (string) $data->get('email', ''), (string) ($data->get('name') ?: $data->get('clinic')));
        if ($existing !== null) {
            $this->fill($existing, $data->all());
            $existing->save();
            $this->event($existing, 'system', 'Aynı telefon ya da site: kart güncellendi.', meta: ['by' => $user?->id]);

            return $existing;
        }
        $source = (string) $data->get('source', '');
        $lead = new SalesLead(['source' => isset(SalesLead::SOURCES[$source]) ? $source : 'other', 'stage' => 'new', 'created_by' => $user?->id]);
        $this->fill($lead, $data->all());
        [$lead->next_step, $lead->next_step_at] = FollowUpSequence::firstMessage();
        if ($lead->website_host !== null) {
            // Same site, another name: a second person of that clinic, or the same one written differently. Ask.
            $lead->duplicate_of_id = SalesLead::query()->where('website_host', $lead->website_host)->orderByDesc('id')->value('id');
        }
        $lead->save();
        $this->event($lead, 'system', 'Kart açıldı ('.$lead->sourceLabel().').', meta: ['by' => $user?->id]);
        if (filled($data->get('note'))) {
            $this->event($lead, 'note', (string) $data->get('note'), meta: ['by' => $user?->id]);
        }
        if ($lead->website_host !== null) {
            ProspectSiteCheck::queue($lead);
        }
        $this->linkConversation($lead);

        return $lead;
    }

    /** Column lengths of what the operator types (the Postgres columns are this wide). */
    public const array LIMITS = ['name' => 255, 'clinic' => 255, 'city' => 120, 'branch' => 120, 'phone' => 40, 'website' => 255, 'notes' => 4000];

    /** Fields the operator types; empty values never erase what is known. */
    public function fill(SalesLead $lead, array $data): void
    {
        foreach (self::LIMITS as $key => $limit) {
            if (array_key_exists($key, $data) && filled($data[$key])) {
                $lead->{$key} = mb_substr(trim((string) $data[$key]), 0, $limit);
            }
        }
        if (filled($data['source'] ?? null) && isset(SalesLead::SOURCES[$data['source']]) && ($lead->source === 'other' || ! $lead->exists)) {
            $lead->source = $data['source'];
        }
        if (($email = self::email((string) ($data['email'] ?? ''))) !== null) {
            $lead->email = $email;
        }
        if (filled($data['heard_from'] ?? null) && isset(SalesLead::HEARD_FROM[$data['heard_from']])) {
            $lead->heard_from = $data['heard_from'];
        }
        if (filled($lead->phone)) {
            $lead->phone_key = self::phoneKey($lead->phone);
        }
        if (filled($lead->website)) {
            $lead->website_host = self::host($lead->website);
            if ($lead->website_host !== null && ! preg_match('#^https?://#i', (string) $lead->website)) {
                $lead->website = 'https://'.ltrim((string) $lead->website, '/');
            }
        }
    }

    /**
     * The same person: an exact phone or e-mail is the same card. A site alone is the same card only when the names do
     * not disagree (two doctors of one clinic share a site but are two cards; the second one asks to be merged).
     */
    public function match(?string $phoneKey, ?string $host, ?string $email = null, ?string $name = null): ?SalesLead
    {
        if ($phoneKey !== null && ($lead = SalesLead::query()->where('phone_key', $phoneKey)->orderByDesc('id')->first()) !== null) {
            return $lead;
        }
        $email = self::email($email);
        if ($email !== null && ($lead = SalesLead::query()->where('email', $email)->orderByDesc('id')->first()) !== null) {
            return $lead;
        }
        if ($host !== null && ($lead = SalesLead::query()->where('website_host', $host)->orderByDesc('id')->first()) !== null) {
            return self::namesAgree($lead, $name) ? $lead : null;
        }

        return null;
    }

    /** A name given that differs from the card's person and clinic means another person (of the same clinic, maybe). */
    public static function namesAgree(SalesLead $lead, ?string $name): bool
    {
        $key = static fn (?string $v): string => (string) preg_replace('/\b(dr|dt|doç|prof|uzm|op)\b\.?|[^\p{L}\p{N}]+/u', '', mb_strtolower(trim((string) $v)));
        $given = $key($name);
        if ($given === '' || ($key($lead->name) === '' && $key($lead->clinic) === '')) {
            return true;
        }

        return in_array($given, array_filter([$key($lead->name), $key($lead->clinic)]), true);
    }

    public static function email(?string $email): ?string
    {
        $email = mb_strtolower(trim((string) $email));

        return filter_var($email, FILTER_VALIDATE_EMAIL) !== false ? mb_substr($email, 0, 255) : null;
    }

    /**
     * Two cards of one person become one: the other card's messages, notes and meetings move here, empty fields are
     * filled from it, and it is deleted.
     */
    public function merge(SalesLead $keep, SalesLead $other, ?User $user = null): void
    {
        if ($keep->is($other)) {
            return;
        }
        foreach (['name', 'clinic', 'city', 'branch', 'phone', 'phone_key', 'email', 'website', 'website_host', 'heard_from', 'conversation_id', 'customer_id', 'brand_id', 'findings', 'site_scan'] as $field) {
            if (blank($keep->{$field}) && filled($other->{$field})) {
                $keep->{$field} = $other->{$field};
            }
        }
        if (filled($other->notes)) {
            $keep->notes = trim(((string) $keep->notes)."\n".$other->notes);
        }
        $keep->duplicate_of_id = null;
        $keep->save();
        SalesLeadEvent::query()->where('lead_id', $other->id)->whereNotNull('fingerprint')
            ->whereIn('fingerprint', SalesLeadEvent::query()->where('lead_id', $keep->id)->whereNotNull('fingerprint')->select('fingerprint'))->delete();
        SalesLeadEvent::query()->where('lead_id', $other->id)->update(['lead_id' => $keep->id]);
        Reminder::query()->where('sales_lead_id', $other->id)->update(['sales_lead_id' => $keep->id]);
        SalesLead::query()->where('duplicate_of_id', $other->id)->update(['duplicate_of_id' => null]);
        $title = $other->title();
        $other->delete();
        $this->event($keep, 'system', 'Kart birleştirildi: '.$title.'.', meta: ['by' => $user?->id]);
    }

    /**
     * A WhatsApp chat that is not a customer's becomes a card (or joins the card with the same phone). Its stage is
     * read from the chat: nobody answered → new; we wrote → contacted; a price in our messages → priced. Old silent
     * chats wait for the re-warm round instead of filling Bugün.
     */
    public function fromConversation(WhatsAppConversation $conversation): ?SalesLead
    {
        if ($conversation->customer_id !== null || ! $this->isInboxConversation($conversation)) {
            return null;
        }
        $phoneKey = self::phoneKey($conversation->contact_id);
        if ($phoneKey !== null && in_array($phoneKey, self::OWN_PHONES, true)) {
            return null;
        }
        $lead = SalesLead::query()->where('conversation_id', $conversation->id)->first()
            ?? ($phoneKey !== null ? SalesLead::query()->where('phone_key', $phoneKey)->orderByDesc('id')->first() : null);
        if ($lead === null) {
            $lead = new SalesLead(['source' => 'whatsapp', 'stage' => 'new']);
        }
        $lead->conversation_id = $conversation->id;
        if (filled($conversation->contact_name) && ! filled($lead->name)) {
            $lead->name = mb_substr((string) $conversation->contact_name, 0, 255);
        }
        if ($phoneKey !== null && ! filled($lead->phone)) {
            $lead->phone = '+'.preg_replace('/\D+/', '', (string) $conversation->contact_id);
            $lead->phone_key = $phoneKey;
        }
        $created = ! $lead->exists;
        $this->readConversation($lead, $conversation, $created);
        $lead->save();
        if ($created) {
            $this->event($lead, 'system', 'WhatsApp görüşmesinden kart açıldı.', $conversation->created_at);
        }

        return $lead;
    }

    /** New messages in a linked chat move the card: they wrote → answer today; linked to a customer → card closes as won. */
    public function refreshFromConversation(WhatsAppConversation $conversation): void
    {
        $lead = SalesLead::query()->where('conversation_id', $conversation->id)->first();
        if ($lead === null) {
            $this->fromConversation($conversation);

            return;
        }
        if ($conversation->customer_id !== null && $lead->isOpen()) {
            $lead->customer_id = $conversation->customer_id;
            $lead->brand_id ??= Brand::query()->where('customer_id', $conversation->customer_id)->value('id');
            $this->setStage($lead, 'won', null, null, 'Görüşme bir müşteriye bağlandı.');

            return;
        }
        if (filled($conversation->contact_name) && ! filled($lead->name)) {
            $lead->name = mb_substr((string) $conversation->contact_name, 0, 255);
        }
        $this->readConversation($lead, $conversation, false);
        $lead->save();
        // They wrote: Claude answers in the background (unless the operator is already editing a text of their own).
        if ($lead->isOpen() && $lead->last_incoming_at !== null && ($lead->last_contact_at === null || $lead->last_incoming_at->gt($lead->last_contact_at))
            && ($lead->draft_at === null || $lead->last_incoming_at->gt($lead->draft_at))
            && ! in_array($lead->draft_status, ['queued', 'running'], true)
            && (! filled($lead->draft) || $lead->draft === $lead->draft_ai)) {
            SalesReplyWriter::queue($lead);
        }
    }

    /** The morning round: every card due today without a text gets one, so Bugün opens with the messages written. */
    public function draftDue(int $limit = 20): int
    {
        $count = 0;
        foreach ($this->due()->whereNull('draft')->where(fn ($q) => $q->whereNull('draft_status')->orWhere('draft_status', 'failed'))->limit($limit)->get() as $lead) {
            SalesReplyWriter::queue($lead);
            $count++;
        }

        return $count;
    }

    private function readConversation(SalesLead $lead, WhatsAppConversation $conversation, bool $created): void
    {
        $lastOut = WhatsAppMessage::query()->where('conversation_id', $conversation->id)->where('direction', 'outgoing')->max('sent_at');
        $lastOut = $lastOut !== null ? CarbonImmutable::parse($lastOut) : null;
        $lastIn = $conversation->last_incoming_at;
        $lead->last_contact_at = $lastOut ?? $lead->last_contact_at;
        $lead->last_incoming_at = $lastIn ?? $lead->last_incoming_at;
        if (! $lead->isOpen()) {
            // They wrote again after we closed the card: it is open once more.
            if ($lead->stage === 'lost' && $lastIn !== null && $lead->lost_at !== null && $lastIn->gt($lead->lost_at)) {
                $lead->stage = 'contacted';
                $lead->lost_at = null;
                $lead->lost_reason = null;
            } else {
                return;
            }
        }
        if ($lastOut !== null) {
            $lead->followup_step = max($lead->followup_step, WhatsAppMessage::query()->where('conversation_id', $conversation->id)->where('direction', 'outgoing')->count() > 0 ? 1 : 0);
            $lead->advanceTo('contacted');
            if ($lastIn !== null) {
                // We wrote and they answered: a conversation.
                $lead->advanceTo('talking');
            }
            if ($this->quotedPrice($conversation)) {
                $lead->advanceTo('priced');
            }
            if ($lastIn !== null && $lastIn->gte($lastOut) && self::isThinking((string) WhatsAppMessage::query()->where('conversation_id', $conversation->id)
                ->where('direction', 'incoming')->orderByDesc('sent_at')->value('body'))) {
                $lead->advanceTo('thinking');
            }
        }
        $theyWroteLast = $lastIn !== null && ($lastOut === null || $lastIn->gt($lastOut));
        if ($theyWroteLast && ! FollowUpSequence::isCold($lead)) {
            [$lead->next_step, $lead->next_step_at] = FollowUpSequence::answerNow();
        } elseif ($lead->next_step_at === null || $created) {
            [$lead->next_step, $lead->next_step_at] = match (true) {
                FollowUpSequence::isCold($lead) => FollowUpSequence::rewarm(),
                $lastOut !== null => FollowUpSequence::afterOurMessage(max(1, $lead->followup_step), CarbonImmutable::instance($lastOut)),
                default => FollowUpSequence::firstMessage(),
            };
            // A follow-up computed from an old message is not overdue by weeks: it is due now at the earliest.
            if ($lead->next_step_at->lt(now()) && ! FollowUpSequence::isCold($lead)) {
                $lead->next_step_at = CarbonImmutable::now('Europe/Istanbul');
            }
        }
    }

    private function quotedPrice(WhatsAppConversation $conversation): bool
    {
        foreach (WhatsAppMessage::query()->where('conversation_id', $conversation->id)->where('direction', 'outgoing')->orderByDesc('sent_at')->limit(60)->get(['body']) as $message) {
            if (preg_match('/\d{1,3}[\.\s]?\d{3}\s*(tl|₺)|\bkdv\b/iu', (string) $message->body)) {
                return true;
            }
        }

        return false;
    }

    /** A card with a phone and no chat yet: the inbox chat with that number joins it (its messages become the timeline). */
    public function linkConversation(SalesLead $lead): bool
    {
        if ($lead->conversation_id !== null || $lead->phone_key === null) {
            return false;
        }
        $inboxId = app(WhatsAppConnection::class)->integration()?->id;
        if ($inboxId === null) {
            return false;
        }
        $conversation = WhatsAppConversation::query()->where('integration_id', $inboxId)->whereNull('customer_id')
            ->where('contact_id', 'like', '%'.$lead->phone_key)->orderByDesc('last_message_at')->first();
        if ($conversation === null || SalesLead::query()->where('conversation_id', $conversation->id)->exists()) {
            return false;
        }
        $lead->conversation_id = $conversation->id;
        $this->readConversation($lead, $conversation, false);
        $lead->save();
        $this->event($lead, 'system', 'WhatsApp görüşmesi karta bağlandı.');

        return true;
    }

    private function isInboxConversation(WhatsAppConversation $conversation): bool
    {
        $inboxId = app(WhatsAppConnection::class)->integration()?->id;

        return $inboxId !== null && (int) $conversation->integration_id === (int) $inboxId;
    }

    /** "Konuşmaları tara": every chat of the inbox that is not a customer's and has no card yet. */
    public function scanConversations(): int
    {
        $inboxId = app(WhatsAppConnection::class)->integration()?->id;
        if ($inboxId === null) {
            return 0;
        }
        $known = SalesLead::query()->whereNotNull('conversation_id')->pluck('conversation_id')->flip();
        $created = 0;
        WhatsAppConversation::query()->where('integration_id', $inboxId)->whereNull('customer_id')
            ->orderBy('id')->chunkById(100, function ($conversations) use (&$created, $known): void {
                foreach ($conversations as $conversation) {
                    if (isset($known[$conversation->id])) {
                        continue;
                    }
                    $created += $this->fromConversation($conversation) !== null ? 1 : 0;
                }
            });

        return $created;
    }

    public function event(SalesLead $lead, string $kind, ?string $body, ?CarbonInterface $at = null, array $meta = [], ?string $fingerprint = null): ?SalesLeadEvent
    {
        if ($fingerprint !== null && SalesLeadEvent::query()->where('lead_id', $lead->id)->where('fingerprint', $fingerprint)->exists()) {
            return null;
        }

        return SalesLeadEvent::query()->create([
            'lead_id' => $lead->id, 'kind' => $kind, 'body' => $body !== null ? mb_substr($body, 0, 8000) : null,
            'meta' => $meta === [] ? null : $meta, 'fingerprint' => $fingerprint, 'happened_at' => $at ?? now(),
        ]);
    }

    public function setStage(SalesLead $lead, string $stage, ?string $reason = null, ?User $user = null, ?string $note = null): void
    {
        if (! isset(SalesLead::STAGES[$stage]) || $lead->stage === $stage) {
            return;
        }
        $from = $lead->stage;
        $lead->stage = $stage;
        if ($stage === 'won') {
            $lead->won_at = now();
            $lead->next_step = null;
            $lead->next_step_at = null;
        } elseif ($stage === 'lost') {
            $lead->lost_at = now();
            $lead->lost_reason = isset(SalesLead::LOST_REASONS[(string) $reason]) ? $reason : 'other';
            $lead->next_step = null;
            $lead->next_step_at = null;
        } else {
            $lead->won_at = null;
            $lead->lost_at = null;
            $lead->lost_reason = null;
            if ($lead->next_step_at === null) {
                [$lead->next_step, $lead->next_step_at] = $stage === 'new' ? FollowUpSequence::firstMessage() : FollowUpSequence::afterOurMessage(max(1, $lead->followup_step));
            }
        }
        $lead->save();
        $this->event($lead, 'stage', $note ?? (SalesLead::STAGES[$from] ?? $from).' → '.$lead->stageLabel().($stage === 'lost' ? ' ('.SalesLead::LOST_REASONS[$lead->lost_reason].')' : ''),
            meta: ['from' => $from, 'to' => $stage, 'by' => $user?->id]);
    }

    /** "Şimdi değil": the card rests until $date, then comes back on Bugün and Takvim to be written to again. */
    public function later(SalesLead $lead, string $date, ?User $user = null): void
    {
        $day = CarbonImmutable::parse($date, 'Europe/Istanbul')->setTime(10, 0);
        $from = $lead->stage;
        $lead->forceFill(['stage' => 'later', 'next_step' => 'Tekrar yaz', 'next_step_at' => $day->utc(), 'won_at' => null, 'lost_at' => null, 'lost_reason' => null])->save();
        $this->event($lead, 'stage', (SalesLead::STAGES[$from] ?? $from).' → Sonra ('.$day->format('d.m.Y').')', meta: ['from' => $from, 'to' => 'later', 'by' => $user?->id]);
    }

    /** A meeting with the prospect was put on the calendar. */
    public function meetingPlanned(SalesLead $lead): void
    {
        if ($lead->advanceTo('meeting')) {
            $lead->save();
        }
    }

    /** Words of a prospect who has not said no but will decide later ("düşüneyim", "ortağıma soracağım", "ay başı"). */
    public static function isThinking(string $text): bool
    {
        return preg_match('/düşün(eyim|eceğim|ücem|cem|elim|üyorum|üp)|değerlendir(eyim|eceğim|ip)|haber ver(eceğim|irim|icem)|dönüş yap(acağım|arım)|size dön(eceğim|erim|cem)|ortağım(a|la)|eşim(e|le) (soray|danış|konuş)|konuşup dön|ay ?başı|bütçe(yi|mizi)? (bakıp|planlay)|şimdilik (bekle|düşün)|biraz (zaman|süre)/iu', $text) === 1;
    }

    /** "Gönderdim": the text went out from the phone; the next follow-up is scheduled. */
    public function markSent(SalesLead $lead, ?string $text, ?User $user = null): void
    {
        $lead->followup_step = $lead->followup_step + 1;
        $lead->last_contact_at = now();
        $lead->advanceTo('contacted');
        if ($text !== null && preg_match('/\d{1,3}[\.\s]?\d{3}\s*(tl|₺)/iu', $text)) {
            $lead->advanceTo('priced');
        }
        [$lead->next_step, $lead->next_step_at] = FollowUpSequence::afterOurMessage($lead->followup_step);
        // What yakup changed in the AI's text is how he writes: the next drafts read his recent edited messages.
        $edited = $text !== null && filled($lead->draft_ai) && self::normalise($text) !== self::normalise((string) $lead->draft_ai);
        $lead->forceFill(['draft' => null, 'draft_ai' => null, 'draft_status' => null, 'draft_note' => null, 'draft_send_on' => null]);
        $lead->save();
        $this->event($lead, 'message_out', $text, meta: ['by' => $user?->id, 'manual' => true, 'edited' => $edited]);
    }

    /** The operator sets the next step by hand (what and when). */
    public function setNextStep(SalesLead $lead, string $text, string $date, ?User $user = null): void
    {
        $text = trim($text);
        $at = CarbonImmutable::parse($date, 'Europe/Istanbul')->setTime(10, 0);
        $lead->next_step = $text !== '' ? mb_substr($text, 0, 255) : 'Takip et';
        $lead->next_step_at = $at;
        $lead->save();
        $this->event($lead, 'system', 'Sonraki adım: '.$lead->next_step.' · '.$at->format('d.m.Y'), meta: ['by' => $user?->id]);
    }

    /** "Kazanıldı → Marka aç": the customer and the brand are created and the card links to them. */
    public function win(SalesLead $lead, ?User $user = null): Brand
    {
        return DB::transaction(function () use ($lead, $user): Brand {
            $brand = $lead->brand_id ? Brand::query()->find($lead->brand_id) : null;
            if ($brand === null) {
                $name = filled($lead->clinic) ? (string) $lead->clinic : $lead->title();
                $customer = $lead->customer_id ? Customer::query()->find($lead->customer_id) : null;
                $customer ??= Customer::query()->create([
                    'name' => $name, 'type' => CustomerType::Company, 'status' => CustomerStatus::Active,
                    'hq_country' => 'TR', 'hq_city' => filled($lead->city) ? $lead->city : null,
                    'primary_phone' => filled($lead->phone) ? $lead->phone : null, 'service_started_at' => now()->toDateString(),
                ]);
                $brand = Brand::query()->create(['customer_id' => $customer->id, 'name' => $name, 'primary_country' => 'TR']);
                $lead->customer_id = $customer->id;
                $lead->brand_id = $brand->id;
            }
            $this->setStage($lead, 'won', null, $user, 'Kazanıldı: '.$brand->name.' markası açıldı.');
            if ($lead->conversation_id !== null) {
                WhatsAppConversation::query()->whereKey($lead->conversation_id)->whereNull('customer_id')
                    ->update(['customer_id' => $lead->customer_id, 'link_source' => 'operator', 'updated_at' => now()]);
            }

            return $brand;
        });
    }

    /**
     * The card's timeline: its events and, when a WhatsApp chat is linked, that chat's messages, newest first.
     *
     * @return Collection<int, array{kind: string, body: string, at: CarbonImmutable, meta: array<string, mixed>}>
     */
    public function timeline(SalesLead $lead, int $limit = 300): Collection
    {
        $rows = $lead->events()->orderByDesc('happened_at')->orderByDesc('id')->limit($limit)->get()
            ->map(fn (SalesLeadEvent $event): array => ['kind' => $event->kind, 'body' => (string) $event->body, 'at' => CarbonImmutable::instance($event->happened_at), 'meta' => (array) ($event->meta ?? [])]);
        if ($lead->conversation_id !== null) {
            $messages = WhatsAppMessage::query()->where('conversation_id', $lead->conversation_id)->orderByDesc('sent_at')->orderByDesc('id')->limit($limit)->get()
                ->map(fn (WhatsAppMessage $message): array => [
                    'kind' => $message->direction === 'outgoing' ? 'message_out' : 'message_in',
                    'body' => (string) $message->body, 'at' => CarbonImmutable::instance($message->sent_at), 'meta' => ['type' => $message->message_type, 'whatsapp' => true],
                ]);
            $rows = $rows->concat($messages);
        }

        return $rows->sortByDesc(fn (array $row): string => $row['at']->format('Y-m-d H:i:s'))->values()->take($limit);
    }

    /** @return Builder<SalesLead> open cards whose next step is today or overdue, the oldest step first */
    public function due(): Builder
    {
        return SalesLead::query()->open()->whereNotNull('next_step_at')->where('next_step_at', '<=', now()->endOfDay())->orderBy('next_step_at');
    }

    /** Customers' chats where the last word is theirs (a request waiting), newest first. */
    public function customerRequests(int $days = 30): Collection
    {
        $inboxId = app(WhatsAppConnection::class)->integration()?->id;
        if ($inboxId === null) {
            return collect();
        }

        return WhatsAppConversation::query()->where('integration_id', $inboxId)->whereNotNull('customer_id')->whereNull('done_at')
            ->whereNotNull('last_incoming_at')->whereColumn('last_incoming_at', '>=', 'last_message_at')
            ->where('last_incoming_at', '>=', now()->subDays($days))->orderByDesc('last_incoming_at')->limit(50)->get();
    }

    /** Monthly counts per source (cards opened, won) for the Sonuçlar tab. */
    public function results(int $months = 6): array
    {
        $since = CarbonImmutable::now('Europe/Istanbul')->startOfMonth()->subMonths($months - 1);
        $rows = SalesLead::query()->where('created_at', '>=', $since)->get(['source', 'stage', 'created_at', 'won_at']);
        $out = [];
        foreach ($rows as $lead) {
            $month = CarbonImmutable::instance($lead->created_at)->setTimezone('Europe/Istanbul')->format('Y-m');
            $out[$month][$lead->source]['opened'] = ($out[$month][$lead->source]['opened'] ?? 0) + 1;
            $out[$month][$lead->source]['won'] = ($out[$month][$lead->source]['won'] ?? 0) + ($lead->stage === 'won' ? 1 : 0);
        }
        krsort($out);

        return $out;
    }

    /** The backup conversations' inbox line is the only WhatsApp source today; kept for the Sonuçlar labels. */
    public static function conversationLine(): string
    {
        return WhatsAppBackupImporter::LINE;
    }

    public static function slug(string $value): string
    {
        return Str::slug($value) ?: 'x';
    }
}
