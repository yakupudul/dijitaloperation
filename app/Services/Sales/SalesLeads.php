<?php

namespace App\Services\Sales;

use App\Enums\CustomerStatus;
use App\Enums\CustomerType;
use App\Models\Brand;
use App\Models\Customer;
use App\Models\Referrer;
use App\Models\Reminder;
use App\Models\SalesLead;
use App\Models\SalesLeadEvent;
use App\Models\SalesOpportunity;
use App\Models\User;
use App\Models\WhatsAppConversation;
use App\Models\WhatsAppMessage;
use App\Services\Assistant\WhatsAppContactLinker;
use App\Services\WhatsApp\Backup\WhatsAppBackupImporter;
use App\Services\WhatsApp\WhatsAppConnection;
use App\Support\Sales\LeadKinds;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * The sales records (Satış): where they come from, what happened, what is next. Rules: a record always has a next step
 * with a date until it is won or lost; a WhatsApp chat that is not a customer's becomes a record by itself; a won
 * record opens the customer, the brand and its agreement, and stays alive for the next sale (an opportunity). The
 * record is generic — its Tür says what kind of business it is. MoxDOP never sends a message — the operator does.
 */
final class SalesLeads
{
    /** The operator's own numbers never become leads (the backup also holds chats with the business itself). */
    public const array OWN_PHONES = ['5388610288', '5319527518'];

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

    /** "Hızlı ekle": a business name, a phone and a source are enough; the first message is due today. */
    public function quickAdd(array $input, ?User $user = null): SalesLead
    {
        $data = collect($input)->map(fn ($value) => is_string($value) ? trim($value) : $value);
        if ($data->get('company', '') === '' && $data->get('clinic', '') !== '') {
            $data->put('company', $data->get('clinic')); // the site's older form field
        }
        $phone = (string) $data->get('phone', '');
        $website = (string) $data->get('website', '');
        if ($data->get('name', '') === '' && $data->get('company', '') === '' && $phone === '' && $website === '' && self::email((string) $data->get('email', '')) === null) {
            throw ValidationException::withMessages(['lead' => 'Bir ad, işletme, telefon ya da site yazın.']);
        }
        $existing = $this->match(self::phoneKey($phone), self::host($website), (string) $data->get('email', ''), (string) ($data->get('name') ?: $data->get('company')));
        if ($existing !== null) {
            $this->fill($existing, $data->all());
            $existing->save();
            $this->event($existing, 'system', 'Aynı telefon ya da site: kart güncellendi.', meta: ['by' => $user?->id]);
            $this->classify($existing);

            return $existing;
        }
        $source = (string) $data->get('source', '');
        $lead = new SalesLead(['source' => isset(SalesLead::SOURCES[$source]) ? $source : 'other', 'stage' => 'new', 'created_by' => $user?->id]);
        $this->fill($lead, $data->all());
        [$lead->next_step, $lead->next_step_at] = FollowUpSequence::firstMessage();
        if ($lead->website_host !== null) {
            // Same site, another name: a second person of that business, or the same one written differently. Ask.
            $lead->duplicate_of_id = SalesLead::query()->where('website_host', $lead->website_host)->orderByDesc('id')->value('id');
        }
        $lead->save();
        $this->event($lead, 'system', 'Kart açıldı ('.$lead->sourceLabel().').', meta: ['by' => $user?->id]);
        $this->classify($lead);
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
    public const array LIMITS = ['name' => 255, 'company' => 255, 'city' => 120, 'branch' => 120, 'phone' => 40, 'website' => 255, 'notes' => 4000];

    /** Fields the operator types; empty values never erase what is known. */
    public function fill(SalesLead $lead, array $data): void
    {
        foreach (self::LIMITS as $key => $limit) {
            if (array_key_exists($key, $data) && filled($data[$key])) {
                $lead->{$key} = mb_substr(trim((string) $data[$key]), 0, $limit);
            }
        }
        if (filled($data['kind'] ?? null) && LeadKinds::has($data['kind'])) {
            $lead->kind = $data['kind'];
            $lead->classification = array_merge((array) ($lead->classification ?? []), ['confirmed' => true]);
        }
        if (array_key_exists('sector_id', $data) && (int) $data['sector_id'] > 0) {
            $lead->sector_id = (int) $data['sector_id'];
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
     * not disagree (two doctors of one business share a site but are two cards; the second one asks to be merged).
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

    /** A name given that differs from the card's person and business means another person (of the same business, maybe). */
    public static function namesAgree(SalesLead $lead, ?string $name): bool
    {
        $key = static fn (?string $v): string => (string) preg_replace('/\b(dr|dt|doç|prof|uzm|op)\b\.?|[^\p{L}\p{N}]+/u', '', mb_strtolower(trim((string) $v)));
        $given = $key($name);
        if ($given === '' || ($key($lead->name) === '' && $key($lead->company) === '')) {
            return true;
        }

        return in_array($given, array_filter([$key($lead->name), $key($lead->company)]), true);
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
        foreach (['name', 'company', 'city', 'branch', 'phone', 'phone_key', 'email', 'website', 'website_host', 'heard_from', 'conversation_id', 'customer_id', 'brand_id', 'findings', 'site_scan', 'kind', 'sector_id'] as $field) {
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
        SalesOpportunity::query()->where('lead_id', $other->id)->update(['lead_id' => $keep->id]);
        Reminder::query()->where('sales_lead_id', $other->id)->update(['sales_lead_id' => $keep->id]);
        SalesLead::query()->where('duplicate_of_id', $other->id)->update(['duplicate_of_id' => null]);
        $title = $other->title();
        $other->delete();
        $this->event($keep, 'system', 'Kart birleştirildi: '.$title.'.', meta: ['by' => $user?->id]);
    }

    /**
     * A WhatsApp chat that is not a customer's becomes a card (or joins the card with the same phone). Its stage is
     * read from the chat: nobody answered → new; we wrote → contacted; a price they asked for or answered → priced. Old silent
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
        $this->backdate($lead, $conversation);
        // An old chat that comes in with the history goes straight to the archive: no card in the lists, no count.
        if ($created && SalesTidy::isStale($lead)) {
            $lead->archived_at = now();
            $lead->archive_reason = 'history';
        }
        $lead->save();
        if ($created) {
            $this->event($lead, 'system', 'WhatsApp görüşmesinden kayıt açıldı.', $conversation->created_at);
            if ($lead->heard_from === 'meta_ads' && is_array($conversation->referral)) {
                $ad = (string) ($conversation->referral['headline'] ?? $conversation->referral['source_id'] ?? '');
                $this->event($lead, 'system', 'Reklamdan yazdı'.($ad !== '' ? ': '.$ad : '.'), $conversation->last_incoming_at, ['referral' => $conversation->referral], 'referral:'.$conversation->id);
            }
        }
        app(DoorScreen::class)->screen($lead, $conversation, $this);

        return $lead;
    }

    /**
     * A card opened from a chat counts from the chat's first message, not from the day the chat reached MoxDOP: a
     * connected number or a phone backup brings years of chats at once, and Pazarlama counts the month's applications
     * by the card's date (yakup 2026-11-08: 553 old chats showed up as November applications). A day's slack keeps a
     * live chat's card where it was.
     */
    private function backdate(SalesLead $lead, WhatsAppConversation $conversation): void
    {
        if ($lead->source !== 'whatsapp' || (int) $lead->conversation_id !== (int) $conversation->id) {
            return;
        }
        $first = WhatsAppMessage::query()->where('conversation_id', $conversation->id)->min('sent_at');
        $opened = $lead->created_at ?? now();
        if ($first !== null && CarbonImmutable::parse($first)->lt(CarbonImmutable::instance($opened)->subDay())) {
            $lead->created_at = CarbonImmutable::parse($first);
        }
    }

    /**
     * New messages in a linked chat move the card: they wrote → answer today; linked to a customer → the record becomes
     * a customer's (won), unless it already is one and is pursuing a new sale, in which case the stage is its own.
     */
    public function refreshFromConversation(WhatsAppConversation $conversation): void
    {
        $lead = SalesLead::query()->where('conversation_id', $conversation->id)->first();
        if ($lead === null) {
            $this->fromConversation($conversation);

            return;
        }
        if ($conversation->customer_id !== null && $lead->isOpen() && ! $lead->isCustomer()) {
            $lead->customer_id = $conversation->customer_id;
            $lead->brand_id ??= Brand::query()->where('customer_id', $conversation->customer_id)->value('id');
            $this->setStage($lead, 'won', null, null, 'Görüşme bir müşteriye bağlandı.');

            return;
        }
        if (filled($conversation->contact_name) && ! filled($lead->name)) {
            $lead->name = mb_substr((string) $conversation->contact_name, 0, 255);
        }
        $this->readConversation($lead, $conversation, false);
        $this->backdate($lead, $conversation);
        if (SalesTidy::isStale($lead)) {
            $lead->archived_at = now();
            $lead->archive_reason = 'history';
        }
        $lead->save();
        if ($lead->history_only || $lead->isArchived()) {
            return;
        }
        // Kapıda eleme (Satış Düzeni 2.2): a patient, a job seeker or a wrong number gets no card, no answer, no research.
        if (app(DoorScreen::class)->screen($lead, $conversation, $this)) {
            return;
        }
        // They wrote: Claude answers in the background (unless the operator is already editing a text of their own).
        if ($lead->isOpen() && $lead->last_incoming_at !== null && ($lead->last_contact_at === null || $lead->last_incoming_at->gt($lead->last_contact_at))
            && ($lead->draft_at === null || $lead->last_incoming_at->gt($lead->draft_at))
            && ! in_array($lead->draft_status, ['queued', 'running'], true)
            && (! filled($lead->draft) || $lead->draft === $lead->draft_ai)) {
            SalesReplyWriter::queue($lead);
        }
        // An unknown number wrote for the first time: Claude looks them up (site, Instagram, harita kaydı).
        LeadResearcher::queueIfNew($lead);
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

    /**
     * A name for a number the backup gives without one: how we greeted them in our own messages ("Aslı Hocam merhaba",
     * "Merhaba Şaban Bey"). Null when we never wrote a name.
     */
    public static function nameFromGreeting(int $conversationId): ?string
    {
        $messages = WhatsAppMessage::query()->where('conversation_id', $conversationId)->where('direction', 'outgoing')
            ->orderBy('sent_at')->limit(40)->get(['body']);
        foreach ($messages as $message) {
            $name = self::greetedName((string) $message->body);
            if ($name !== null) {
                return $name;
            }
        }

        return null;
    }

    public static function greetedName(string $text): ?string
    {
        $head = (string) preg_replace('/^(merhaba|selam|günaydın|iyi günler)[,!.\s]+/iu', '', mb_substr(trim($text), 0, 80));
        $word = '[A-ZÇĞİÖŞÜ][a-zçğıöşü]+';
        $pattern = '/^((?:(?:Dr|Dt|Uzm|Prof|Doç)\.\s*)?'.$word.'(?:\s'.$word.')?)\s+(Hocam|Bey|Hanım)\b/u';
        if (preg_match($pattern, $head, $m) !== 1) {
            return null;
        }
        $first = mb_strtolower(explode(' ', $m[1])[0]);
        if (in_array($first, ['merhaba', 'selam', 'günaydın', 'iyi', 'sayın', 'değerli', 'kıymetli'], true)) {
            return null;
        }

        return $m[1].' '.$m[2];
    }

    private function readConversation(SalesLead $lead, WhatsAppConversation $conversation, bool $created): void
    {
        if (! filled($lead->name)) {
            $lead->name = self::nameFromGreeting($conversation->id);
        }
        $lastOut = WhatsAppMessage::query()->where('conversation_id', $conversation->id)->where('direction', 'outgoing')->max('sent_at');
        $lastOut = $lastOut !== null ? CarbonImmutable::parse($lastOut) : null;
        // What they last said that can be read: a message WhatsApp did not share ("Okunamayan mesaj") moves nothing, the
        // card does not start owing an answer for it (Marka Düzeni 1.6).
        $readableIn = WhatsAppMessage::query()->where('conversation_id', $conversation->id)->where('direction', 'incoming')
            ->where(fn ($q) => $q->whereNull('message_type')->orWhereNotIn('message_type', WhatsAppMessage::UNREADABLE_TYPES))->max('sent_at');
        $lastIn = $readableIn !== null ? CarbonImmutable::parse($readableIn) : null;
        // Imported history (the phone backup, Meta's history sync) tells what was said, not whether it was answered on
        // the phone: until a live message arrives the card owes nothing and gets no automatic step (yakup 2026-11-09).
        $messages = WhatsAppMessage::query()->where('conversation_id', $conversation->id);
        $lead->history_only = (clone $messages)->exists() && ! (clone $messages)->where('is_history', false)->exists();
        if (! $lead->history_only && $lead->archived_at !== null) {
            $lead->archived_at = null;
            $lead->archive_reason = null;
            if ($lead->exists) {
                $this->event($lead, 'system', 'Arşivden döndü: canlı hatta yeni mesaj var.');
            }
        }
        // A call or a meeting logged by hand can be newer than the last WhatsApp message: keep the later one.
        $lead->last_contact_at = $lastOut !== null && ($lead->last_contact_at === null || $lastOut->gt($lead->last_contact_at)) ? $lastOut : $lead->last_contact_at;
        $unreadableIn = WhatsAppMessage::query()->where('conversation_id', $conversation->id)->where('direction', 'incoming')
            ->whereIn('message_type', WhatsAppMessage::UNREADABLE_TYPES)->max('sent_at');
        // (A time stamped earlier by an unreadable message is taken back; a form or a hand note keeps its own time.)
        $lead->last_incoming_at = SalesTidy::unreadableStamp($lead, $unreadableIn, $readableIn) ? $lastIn : ($lastIn ?? $lead->last_incoming_at);
        $this->readReferral($lead, $conversation);
        if (! $lead->isOpen()) {
            // They wrote again after we closed the card: it is open once more (an "İlgisiz" record becomes a prospect again).
            // (Somebody put aside at the door stays there however often they write; only "Adaya çevir" brings them back.)
            if ($lead->stage === 'lost' && $lastIn !== null && $lead->lost_at !== null && $lastIn->gt($lead->lost_at) && DoorScreen::verdict($lead) === null) {
                $lead->stage = 'contacted';
                $lead->lost_at = null;
                $lead->lost_reason = null;
                if ($lead->lifecycle === 'irrelevant') {
                    $lead->lifecycle = 'prospect';
                }
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
            // "Teklif verildi" is the prospect's move, not ours (Satış Düzeni 1.1): a price in our own message counts only
            // when they asked for it or answered after seeing it. The amount is still remembered as read from the message.
            $priced = $this->quotedPrice($conversation);
            if ($priced !== null) {
                $lead->suggestOfferFrom($priced['body']);
                if ($this->prospectEngagedWithPrice($conversation, $priced['at'])) {
                    $lead->advanceTo('priced');
                } elseif ($lead->stage === 'priced' && ! $lead->offer_confirmed && ! $this->stageSetByHand($lead, 'priced')) {
                    // Read as "priced" by the old rule: back to where the chat really is.
                    $lead->stage = $lastIn !== null ? 'talking' : 'contacted';
                }
            }
            if ($lastIn !== null && $lastIn->gte($lastOut) && self::isThinking((string) WhatsAppMessage::query()->where('conversation_id', $conversation->id)
                ->where('direction', 'incoming')->orderByDesc('sent_at')->value('body'))) {
                $lead->advanceTo('thinking');
            }
        }
        $theyWroteLast = $lastIn !== null && ($lastOut === null || $lastIn->gt($lastOut));
        if ($lead->history_only && ($theyWroteLast || $lastOut === null)) {
            // History alone makes no debt: "they wrote last" or "nobody wrote yet" may have been answered on the phone. The
            // step waits for a live message or the operator's own plan. A follow-up after our own message stays.
            if ($created || SalesTidy::isUnsafeStep($lead->next_step)) {
                $lead->next_step = null;
                $lead->next_step_at = null;
            }

            return;
        }
        if ($theyWroteLast && ! FollowUpSequence::isCold($lead)) {
            [$lead->next_step, $lead->next_step_at] = FollowUpSequence::answerNow();
        } elseif ($lead->next_step_at === null || $created || ($lastOut !== null && SalesTidy::isUnsafeStep($lead->next_step))) {
            // ("Cevap yaz" / "İlk mesajı gönder" after we already wrote is done: the follow-up after our message takes its place.)
            [$lead->next_step, $lead->next_step_at] = match (true) {
                FollowUpSequence::isCold($lead) => FollowUpSequence::rewarm(),
                $lastOut !== null => FollowUpSequence::afterOurMessage(max(1, $lead->followup_step), CarbonImmutable::instance($lastOut)),
                default => FollowUpSequence::firstMessage(),
            };
            // A follow-up computed from an old message is not overdue by weeks: it is due now at the earliest.
            if ($lead->next_step_at !== null && $lead->next_step_at->lt(now()) && ! FollowUpSequence::isCold($lead)) {
                $lead->next_step_at = CarbonImmutable::now('Europe/Istanbul');
            }
        }
    }

    /** A chat that started from a Click-to-WhatsApp ad: the record's source is that ad (Meta sends it with the first message). */
    private function readReferral(SalesLead $lead, WhatsAppConversation $conversation): void
    {
        $referral = $conversation->referral;
        if (! is_array($referral) || $referral === [] || $lead->heard_from !== null) {
            return;
        }
        $ad = (string) ($referral['headline'] ?? $referral['source_id'] ?? '');
        $lead->heard_from = 'meta_ads';
        // The same shape the site form writes (utm_*), so the funnel reads ads from both channels alike.
        $lead->origin = array_merge((array) $lead->origin, array_filter([
            'utm_source' => ($referral['source_type'] ?? '') === 'post' ? 'facebook' : 'meta', 'utm_medium' => 'ctwa',
            'utm_campaign' => $ad !== '' ? mb_substr($ad, 0, 160) : null, 'ad_id' => $referral['source_id'] ?? null, 'ad_url' => $referral['source_url'] ?? null,
            'ctwa_clid' => $referral['ctwa_clid'] ?? null, 'at' => $referral['at'] ?? null,
        ], fn ($v): bool => $v !== null && $v !== ''));
        if ($lead->exists) {
            $this->event($lead, 'system', 'Reklamdan yazdı'.($ad !== '' ? ': '.$ad : '.'), $conversation->last_incoming_at, ['referral' => $referral], 'referral:'.$conversation->id);
        }
    }

    /** The newest text we sent that names a price (or KDV), or null when no price was written yet. */
    /** @return array{body: string, at: ?CarbonImmutable}|null our newest message that names a price */
    private function quotedPrice(WhatsAppConversation $conversation): ?array
    {
        foreach (WhatsAppMessage::query()->where('conversation_id', $conversation->id)->where('direction', 'outgoing')->orderByDesc('sent_at')->limit(60)->get(['body', 'sent_at']) as $message) {
            if (preg_match('/\d{1,3}[\.\s]?\d{3}\s*(tl|₺)|\bkdv\b/iu', (string) $message->body)) {
                return ['body' => (string) $message->body, 'at' => $message->sent_at !== null ? CarbonImmutable::instance($message->sent_at) : null];
            }
        }

        return null;
    }

    /** The prospect asked what it costs, or wrote something readable after our price message. */
    private function prospectEngagedWithPrice(WhatsAppConversation $conversation, ?CarbonImmutable $pricedAt): bool
    {
        $incoming = WhatsAppMessage::query()->where('conversation_id', $conversation->id)->where('direction', 'incoming')
            ->where(fn ($q) => $q->whereNull('message_type')->orWhereNotIn('message_type', WhatsAppMessage::UNREADABLE_TYPES))
            ->orderByDesc('sent_at')->limit(60)->get(['body', 'sent_at']);
        foreach ($incoming as $message) {
            if ($pricedAt !== null && $message->sent_at !== null && $message->sent_at->gt($pricedAt)) {
                return true;
            }
            if (self::asksPrice((string) $message->body)) {
                return true;
            }
        }

        return false;
    }

    public static function asksPrice(string $text): bool
    {
        return preg_match('/fiyat|ücret|ne kadar|kaç\s*(tl|lira|para)|kaça|tutar|maliyet|bütçe|aylı[kğ]ı? ne/iu', $text) === 1;
    }

    /** The operator chose this stage on the card (a stage event with a user), so no rule moves the card out of it. */
    private function stageSetByHand(SalesLead $lead, string $stage): bool
    {
        if (! $lead->exists) {
            return false;
        }

        return SalesLeadEvent::query()->where('lead_id', $lead->id)->where('kind', 'stage')->orderByDesc('id')->limit(20)->get(['meta'])
            ->contains(fn (SalesLeadEvent $event): bool => data_get($event->meta, 'to') === $stage && data_get($event->meta, 'by') !== null);
    }

    /**
     * Was this chat card put at "Teklif verildi" only because our own message carried a price (the rule before Satış
     * Düzeni 1.1)? Then the stage it really is at; null when the offer stands (they asked or answered, the operator
     * confirmed or chose it) or the card has no chat to read.
     */
    public function stageWithoutOurOwnPrice(SalesLead $lead): ?string
    {
        if ($lead->stage !== 'priced' || $lead->offer_confirmed || $lead->conversation_id === null || $this->stageSetByHand($lead, 'priced')) {
            return null;
        }
        $conversation = WhatsAppConversation::query()->find($lead->conversation_id);
        if ($conversation === null || ($priced = $this->quotedPrice($conversation)) === null || $this->prospectEngagedWithPrice($conversation, $priced['at'])) {
            return null;
        }

        return $lead->last_incoming_at !== null ? 'talking' : 'contacted';
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
        if ($stage === 'lost' && $lead->isCustomer() && ($open = $lead->openOpportunity()) !== null) {
            // A customer does not get lost: the sale being pursued does, and the record goes back to "müşteri".
            $this->closeOpportunity($open, 'lost', $reason, $user);

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
            // Our price alone is not an offer stage (Satış Düzeni 1.1); it is one when they had asked what it costs.
            $lead->suggestOfferFrom($text);
            $conversation = $lead->conversation_id !== null ? WhatsAppConversation::query()->find($lead->conversation_id) : null;
            if ($conversation !== null && $this->prospectEngagedWithPrice($conversation, null)) {
                $lead->advanceTo('priced');
            }
        }
        [$lead->next_step, $lead->next_step_at] = FollowUpSequence::afterOurMessage($lead->followup_step);
        // What yakup changed in the AI's text is how he writes: the next drafts read his recent edited messages.
        $edited = $text !== null && filled($lead->draft_ai) && self::normalise($text) !== self::normalise((string) $lead->draft_ai);
        $lead->forceFill(['draft' => null, 'draft_ai' => null, 'draft_status' => null, 'draft_note' => null, 'draft_send_on' => null]);
        $lead->save();
        $this->event($lead, 'message_out', $text, meta: ['by' => $user?->id, 'manual' => true, 'edited' => $edited]);
    }

    /** How a talk outside WhatsApp happened ("Temas kaydet"). */
    public const array TOUCH_CHANNELS = ['phone' => 'Telefon', 'in_person' => 'Yüz yüze', 'video' => 'Görüntülü', 'other' => 'Diğer'];

    /**
     * "Temas kaydet": a call or a meeting the backup cannot see. It is written on the card, counts as our last contact,
     * moves the stage forward, and ends either with the next step (what + when) or with the card closed for a reason.
     *
     * @param  array{channel?: string, body?: string, next?: string, date?: string, close?: string}  $input
     */
    public function logTouch(SalesLead $lead, array $input, ?User $user = null): void
    {
        $channel = isset(self::TOUCH_CHANNELS[$input['channel'] ?? '']) ? (string) $input['channel'] : 'phone';
        $body = trim((string) ($input['body'] ?? ''));
        $close = (string) ($input['close'] ?? '');
        $date = trim((string) ($input['date'] ?? ''));
        $errors = [];
        if ($body === '') {
            $errors['touch.body'] = 'Ne konuştuğunuzu bir cümleyle yaz.';
        }
        if ($close === '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) !== 1) {
            $errors['touch.date'] = 'Sonraki adımın tarihini seç ya da kartı kapat.';
        }
        if ($close !== '' && ! isset(SalesLead::LOST_REASONS[$close])) {
            $errors['touch.close'] = 'Kapanış nedenini seç.';
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
        $this->event($lead, $channel === 'phone' ? 'call' : 'meeting', self::TOUCH_CHANNELS[$channel].': '.mb_substr($body, 0, 4000), meta: ['by' => $user?->id, 'channel' => $channel]);
        $lead->last_contact_at = now();
        if ($lead->stage === 'later' || $lead->isOpen()) {
            $lead->advanceTo($channel === 'phone' ? 'talking' : 'meeting');
        }
        $lead->save();
        if ($close !== '') {
            $this->setStage($lead, 'lost', $close, $user);

            return;
        }
        if (! $lead->isOpen()) {
            $this->setStage($lead, 'talking', null, $user, 'Yeniden açıldı: '.self::TOUCH_CHANNELS[$channel].' görüşmesi.');
        }
        $this->setNextStep($lead, (string) ($input['next'] ?? ''), $date, $user);
    }

    /** "Kapat": a card closes only with a reason (the month report's loss reasons depend on it). */
    public function close(SalesLead $lead, string $reason, ?string $note = null, ?User $user = null): void
    {
        if (! isset(SalesLead::LOST_REASONS[$reason])) {
            throw ValidationException::withMessages(['close' => 'Kapanış nedenini seç.']);
        }
        $note = trim((string) $note);
        $this->setStage($lead, 'lost', $reason, $user);
        if ($note !== '') {
            $this->event($lead, 'note', mb_substr($note, 0, 4000), meta: ['by' => $user?->id]);
        }
    }

    /** The offer the operator confirms by hand (monthly ₺ + package); 0 / empty clears it. */
    public function setOffer(SalesLead $lead, ?int $amount, ?string $service, ?User $user = null): void
    {
        $amount = $amount !== null && $amount > 0 ? min($amount, 9_999_999) : null;
        $service = $service !== null && isset(SalesLead::SERVICES[$service]) ? $service : null;
        $lead->forceFill(['offer_amount' => $amount, 'offer_service' => $amount !== null ? $service : null, 'offer_confirmed' => $amount !== null])->save();
        $this->event($lead, 'offer', $amount !== null ? 'Teklif: '.number_format($amount, 0, ',', '.').' ₺/ay'.($service !== null ? ' · '.SalesLead::SERVICES[$service] : '') : 'Teklif silindi.', meta: ['by' => $user?->id]);
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

    /**
     * "Kazanıldı → Müşteri yap": the customer and the brand are created (once), the card links to them, the open
     * opportunity (or one made from the offer) is won, and the brand's agreement starts when terms were given.
     *
     * @param  array{kind?: string, fee?: int|string, term_months?: int|string|null, starts_on?: string, due_day?: int|string, services?: array<int, string>|null, note?: string|null}|null  $agreement
     */
    public function win(SalesLead $lead, ?User $user = null, ?array $agreement = null): Brand
    {
        return DB::transaction(function () use ($lead, $user, $agreement): Brand {
            $brand = $lead->brand_id ? Brand::query()->find($lead->brand_id) : null;
            if ($brand === null) {
                $name = filled($lead->company) ? (string) $lead->company : $lead->title();
                $customer = $lead->customer_id ? Customer::query()->find($lead->customer_id) : null;
                $customer ??= Customer::query()->create([
                    'name' => $name, 'type' => CustomerType::Company, 'status' => CustomerStatus::Active,
                    'hq_country' => 'TR', 'hq_city' => filled($lead->city) ? $lead->city : null,
                    'primary_phone' => filled($lead->phone) ? $lead->phone : null, 'service_started_at' => now()->toDateString(),
                ]);
                $brand = Brand::query()->create(['customer_id' => $customer->id, 'name' => $name, 'primary_country' => 'TR', 'sector_id' => $lead->sector_id]);
                $lead->customer_id = $customer->id;
                $lead->brand_id = $brand->id;
            }
            $opportunity = $lead->openOpportunity() ?? $this->opportunityFromOffer($lead, $user);
            $this->setStage($lead, 'won', null, $user, 'Kazanıldı: '.$brand->name.' markası açıldı.');
            if ($opportunity !== null) {
                $opportunity->forceFill(['status' => 'won', 'won_at' => now()])->save();
            }
            if ($agreement !== null && (int) preg_replace('/\D+/', '', (string) ($agreement['fee'] ?? '')) > 0) {
                $terms = app(Agreements::class)->start($brand, $agreement, $user, $lead, $opportunity);
                $this->event($lead, 'offer', 'Anlaşma: '.$terms->feeLabel().' · '.$terms->kindLabel().' · ödeme günü '.$terms->due_day, meta: ['by' => $user?->id, 'agreement_id' => $terms->id]);
            }
            if ($lead->conversation_id !== null) {
                WhatsAppConversation::query()->whereKey($lead->conversation_id)->whereNull('customer_id')
                    ->update(['customer_id' => $lead->customer_id, 'link_source' => 'operator', 'updated_at' => now()]);
            }

            return $brand;
        });
    }

    /** The first sale as an opportunity, from the card's offer (null when nothing was offered). */
    private function opportunityFromOffer(SalesLead $lead, ?User $user = null): ?SalesOpportunity
    {
        if ($lead->offer_amount === null && $lead->offer_service === null) {
            return null;
        }

        return SalesOpportunity::query()->create([
            'lead_id' => $lead->id, 'service' => $lead->offer_service, 'amount' => $lead->offer_amount, 'billing' => $lead->offer_service === 'web' ? 'one_time' : 'monthly',
            'status' => 'open', 'created_by' => $user?->id,
        ]);
    }

    /**
     * "Yeni fırsat": a sale to pursue on this record — the first one of a prospect, or an upsell to a customer
     * (a raise, a second service, a one-time site). A customer's card comes back to the open stages for it.
     *
     * @param  array{title?: string, service?: string, amount?: int|string, billing?: string, note?: string}  $data
     */
    public function openOpportunity(SalesLead $lead, array $data, ?User $user = null): SalesOpportunity
    {
        $amount = (int) preg_replace('/\D+/', '', (string) ($data['amount'] ?? ''));
        $service = isset(SalesLead::SERVICES[(string) ($data['service'] ?? '')]) ? (string) $data['service'] : null;
        $billing = isset(SalesOpportunity::BILLINGS[(string) ($data['billing'] ?? '')]) ? (string) $data['billing'] : 'monthly';
        $title = mb_substr(trim((string) ($data['title'] ?? '')), 0, 255);
        if ($service === null && $title === '') {
            throw ValidationException::withMessages(['opportunity.service' => 'Hangi hizmet? Birini seç ya da başlık yaz.']);
        }
        if (($open = $lead->openOpportunity()) !== null) {
            throw ValidationException::withMessages(['opportunity.service' => 'Bu kartta açık bir fırsat var: '.$open->title().'. Önce onu kazan ya da kapat.']);
        }
        $opportunity = SalesOpportunity::query()->create([
            'lead_id' => $lead->id, 'title' => $title !== '' ? $title : null, 'service' => $service, 'amount' => $amount > 0 ? min($amount, 9_999_999) : null,
            'billing' => $billing, 'status' => 'open', 'note' => mb_substr(trim((string) ($data['note'] ?? '')), 0, 2000) ?: null, 'created_by' => $user?->id,
        ]);
        if ($billing === 'monthly' && $amount > 0) {
            $lead->forceFill(['offer_amount' => $opportunity->amount, 'offer_service' => $service, 'offer_confirmed' => true]);
        }
        if (! $lead->isOpen()) {
            // A customer's card is pursued again: open stage, a step for today.
            $lead->forceFill(['stage' => $amount > 0 ? 'priced' : 'talking', 'won_at' => null, 'lost_at' => null, 'lost_reason' => null, 'next_step' => 'Fırsatı konuş: '.$opportunity->title(), 'next_step_at' => CarbonImmutable::now('Europe/Istanbul')]);
        } elseif ($amount > 0) {
            $lead->advanceTo('priced');
        }
        $lead->save();
        $this->event($lead, 'offer', 'Yeni fırsat: '.$opportunity->title().' · '.$opportunity->amountLabel(), meta: ['by' => $user?->id, 'opportunity_id' => $opportunity->id]);

        return $opportunity;
    }

    /**
     * An opportunity ends: won (a customer's brand gets the new agreement; a prospect's record is won through win())
     * or lost (a customer's record goes back to "müşteri"; a prospect's record is lost with the reason).
     *
     * @param  array<string, mixed>|null  $agreement  terms for a won one
     */
    public function closeOpportunity(SalesOpportunity $opportunity, string $outcome, ?string $reason = null, ?User $user = null, ?array $agreement = null): void
    {
        $lead = $opportunity->lead;
        if ($outcome === 'won') {
            if ($lead->brand_id === null || ! $lead->isCustomer()) {
                $this->win($lead, $user, $agreement);

                return;
            }
            $opportunity->forceFill(['status' => 'won', 'won_at' => now()])->save();
            $brand = Brand::query()->find($lead->brand_id);
            if ($brand !== null && $agreement !== null && (int) preg_replace('/\D+/', '', (string) ($agreement['fee'] ?? '')) > 0) {
                $terms = app(Agreements::class)->start($brand, $agreement, $user, $lead, $opportunity);
                $this->event($lead, 'offer', 'Anlaşma yenilendi: '.$terms->feeLabel().' · '.$terms->kindLabel(), meta: ['by' => $user?->id, 'agreement_id' => $terms->id]);
            }
            $lead->forceFill(['stage' => 'won', 'won_at' => now(), 'next_step' => null, 'next_step_at' => null])->save();
            $this->event($lead, 'stage', 'Fırsat kazanıldı: '.$opportunity->title().' · '.$opportunity->amountLabel(), meta: ['to' => 'won', 'by' => $user?->id, 'opportunity_id' => $opportunity->id]);

            return;
        }
        $reason = isset(SalesLead::LOST_REASONS[(string) $reason]) ? (string) $reason : 'other';
        $opportunity->forceFill(['status' => 'lost', 'lost_at' => now(), 'lost_reason' => $reason])->save();
        if ($lead->isCustomer()) {
            $lead->forceFill(['stage' => 'won', 'next_step' => null, 'next_step_at' => null, 'lost_at' => null, 'lost_reason' => null])->save();
            $this->event($lead, 'stage', 'Fırsat kaybedildi: '.$opportunity->title().' ('.SalesLead::LOST_REASONS[$reason].'); müşteri olarak sürüyor.', meta: ['to' => 'won', 'by' => $user?->id, 'opportunity_id' => $opportunity->id]);

            return;
        }
        $this->setStage($lead, 'lost', $reason, $user);
    }

    /**
     * "Kaydı sil" (yakup 2026-11-11: test records): a record typed by hand or sent by a form that should never have
     * existed goes for good, with its history. A customer, a record with a brand and a record born from a chat are
     * never deleted (the chat would open it again; "Aday olmayan" is the way out for those).
     */
    public function remove(SalesLead $lead): void
    {
        if ($lead->isCustomer() || $lead->brand_id !== null || $lead->customer_id !== null || $lead->stage === 'won') {
            throw ValidationException::withMessages(['lead' => 'Müşteri kaydı silinmez.']);
        }
        if ($lead->conversation_id !== null) {
            throw ValidationException::withMessages(['lead' => 'WhatsApp görüşmesinden açılan kayıt silinmez; "Aday olmayan" yap.']);
        }
        Log::info('sales.lead.removed', ['id' => $lead->id, 'title' => $lead->title(), 'source' => $lead->source, 'by' => auth()->id()]);
        $lead->delete();
    }

    /** "İlgisiz": this chat was never a prospect (a supplier, a friend, spam). Out of every list and report. */
    public function markIrrelevant(SalesLead $lead, ?User $user = null): void
    {
        $from = $lead->stage;
        $lead->forceFill(['lifecycle' => 'irrelevant', 'stage' => 'lost', 'lost_at' => now(), 'lost_reason' => 'irrelevant', 'next_step' => null, 'next_step_at' => null, 'draft' => null, 'draft_ai' => null, 'draft_status' => null])->save();
        $this->event($lead, 'stage', (SalesLead::STAGES[$from] ?? $from).' → İlgisiz', meta: ['from' => $from, 'to' => 'lost', 'by' => $user?->id]);
    }

    /**
     * The six-status table (yakup 2026-11-08): moves a record to one status. Devam eden / Güçlü aday / Kararsız reopen a
     * closed card; Olumsuz needs a reason (the close dialog); Aday olmayan = İlgisiz; Müşteri opens the customer and
     * brand (once) and starts the referral reward. A customer is never Olumsuz or Aday olmayan.
     */
    public function setStatus(SalesLead $lead, string $status, ?User $user = null, ?string $reason = null, ?string $note = null): void
    {
        if (! isset(SalesLead::STATUSES[$status])) {
            throw ValidationException::withMessages(['status' => 'Bilinmeyen durum.']);
        }
        if ($status === $lead->status()) {
            return;
        }
        if ($lead->isCustomer() && in_array($status, ['olumsuz', 'aday_degil'], true)) {
            throw ValidationException::withMessages(['status' => 'Bir müşteri olumsuz ya da aday olmayan yapılamaz; açık fırsatı kartından kapat.']);
        }
        if ($lead->archived_at !== null) {
            $lead->archived_at = null;
            $lead->archive_reason = null;
        }
        if ($lead->isDirty(['archived_at'])) {
            $lead->save();
        }
        if ($status === 'musteri') {
            $this->win($lead, $user);
            app(ReferralRewards::class)->onWon($lead->fresh());

            return;
        }
        if ($status === 'aday_degil') {
            $lead->forceFill(['is_strong' => false])->save();
            $this->markIrrelevant($lead, $user);

            return;
        }
        if ($status === 'olumsuz') {
            $lead->forceFill(['is_strong' => false])->save();
            $this->close($lead, (string) $reason, $note, $user);

            return;
        }
        // An open status: a closed or irrelevant card comes back to the open line.
        if ($lead->lifecycle === 'irrelevant') {
            DoorScreen::keep($lead);
            $lead->lifecycle = 'prospect';
        }
        $lead->is_strong = $status === 'guclu';
        if ($status === 'guclu') {
            $lead->strong_hint = null;
        }
        // One save with the new stage, so a card closed as ilgisiz does not fall back to it on the way.
        if ($status === 'kararsiz' && $lead->stage !== 'thinking') {
            $this->setStage($lead, 'thinking', null, $user);
        } elseif ($status !== 'kararsiz' && (! $lead->isOpen() || in_array($lead->stage, ['thinking', 'later'], true))) {
            $this->setStage($lead, 'talking', null, $user);
        } else {
            $lead->save();
        }
        if ($status === 'guclu') {
            $this->event($lead, 'stage', 'Güçlü aday olarak işaretlendi.', meta: ['by' => $user?->id, 'strong' => true]);
        }
    }

    /**
     * Kimden: Moximu or a referral. A referral names who sent them: a customer (customer_id) or someone on the
     * Referanslar list (referrer_id). A record that is already a customer gets its reward row at once.
     *
     * @param  array{via?: string, customer_id?: int|string|null, referrer_id?: int|string|null}  $input
     */
    public function setCameVia(SalesLead $lead, array $input, ?User $user = null): void
    {
        $via = (string) ($input['via'] ?? '');
        if (! isset(SalesLead::CAME_VIA[$via])) {
            throw ValidationException::withMessages(['from.via' => 'Moximu ya da Referans seç.']);
        }
        $customerId = (int) ($input['customer_id'] ?? 0) ?: null;
        $referrerId = (int) ($input['referrer_id'] ?? 0) ?: null;
        if ($via === 'referral') {
            if ($customerId === null && $referrerId === null) {
                throw ValidationException::withMessages(['from.who' => 'Referansı seç: bir müşteri ya da Referanslar listesinden biri.']);
            }
            if ($customerId !== null && ($customerId === (int) $lead->customer_id || ! Customer::query()->whereKey($customerId)->exists())) {
                throw ValidationException::withMessages(['from.who' => 'Bu müşteri seçilemez.']);
            }
            if ($referrerId !== null && ! Referrer::query()->whereKey($referrerId)->exists()) {
                throw ValidationException::withMessages(['from.who' => 'Bu referans listede yok.']);
            }
        }
        $lead->forceFill([
            'came_via' => $via,
            'referrer_customer_id' => $via === 'referral' ? $customerId : null,
            'referrer_id' => $via === 'referral' && $customerId === null ? $referrerId : null,
            'heard_from' => $via === 'referral' ? 'referral' : ($lead->heard_from === 'referral' ? null : $lead->heard_from),
        ])->save();
        $this->event($lead, 'system', 'Kimden: '.$lead->cameViaLabel(), meta: ['by' => $user?->id]);
        app(ReferralRewards::class)->onWon($lead->fresh());
    }

    /**
     * Hatırlatma / toplantı from the table: one row on Takvim (and the phone that morning), and the card's Yapılacak
     * line says it ("Toplantı · 10.11 14:00").
     *
     * @param  array{kind?: string, title?: string, date?: string, time?: string}  $input
     */
    public function plan(SalesLead $lead, array $input, ?User $user = null): Reminder
    {
        $kind = ($input['kind'] ?? 'task') === 'meeting' ? 'meeting' : 'task';
        $date = trim((string) ($input['date'] ?? ''));
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) !== 1) {
            throw ValidationException::withMessages(['plan.date' => 'Tarihi seç.']);
        }
        $time = preg_match('/^\d{2}:\d{2}$/', (string) ($input['time'] ?? '')) === 1 ? (string) $input['time'] : '10:00';
        $at = CarbonImmutable::parse($date.' '.$time, 'Europe/Istanbul');
        $title = trim((string) ($input['title'] ?? '')) ?: ($kind === 'meeting' ? 'Toplantı' : 'Hatırlat');
        $reminder = Reminder::query()->create([
            'user_id' => $user?->id, 'customer_id' => $lead->customer_id, 'brand_id' => $lead->brand_id, 'sales_lead_id' => $lead->id,
            'kind' => $kind, 'title' => mb_substr($title.' · '.$lead->title(), 0, 255), 'remind_at' => $at->utc(),
            'ends_at' => $kind === 'meeting' ? $at->addHour()->utc() : null, 'repeat' => 'none',
        ]);
        $lead->next_step = mb_substr($title, 0, 255);
        $lead->next_step_at = $at->utc();
        $lead->save();
        if ($kind === 'meeting' && $lead->isOpen() && array_search($lead->stage, SalesLead::FLOW, true) < array_search('meeting', SalesLead::FLOW, true)) {
            $this->setStage($lead, 'meeting', null, $user);
        }
        $this->event($lead, 'system', ($kind === 'meeting' ? 'Toplantı planlandı: ' : 'Hatırlatma: ').$title.' · '.$at->format('d.m.Y H:i'), meta: ['by' => $user?->id, 'reminder_id' => $reminder->id]);

        return $reminder;
    }

    /** The sales record of an existing customer (made on first need, so a customer can get a "Yeni fırsat" like anyone). */
    public function forCustomer(Customer $customer, ?Brand $brand = null): SalesLead
    {
        $lead = SalesLead::query()->where('customer_id', $customer->id)->orderByDesc('id')->first();
        if ($lead !== null) {
            return $lead;
        }
        $brand ??= Brand::query()->where('customer_id', $customer->id)->orderBy('id')->first();
        $lead = new SalesLead(['source' => 'other', 'stage' => 'won', 'lifecycle' => 'customer', 'customer_id' => $customer->id, 'brand_id' => $brand?->id,
            'name' => null, 'company' => mb_substr((string) $customer->name, 0, 255), 'city' => $customer->hq_city, 'phone' => $customer->primary_phone,
            'email' => self::email($customer->primary_email), 'sector_id' => $brand?->sector_id, 'won_at' => $customer->service_started_at ?? $customer->created_at]);
        $lead->phone_key = self::phoneKey((string) $lead->phone);
        // The customer's WhatsApp chat (already linked to the customer) is the record's timeline.
        $lead->conversation_id = WhatsAppConversation::query()->where('customer_id', $customer->id)->orderByDesc('last_message_at')->value('id');
        $lead->save();
        $this->event($lead, 'system', 'Mevcut müşteri için kayıt açıldı.');
        $this->classify($lead);

        return $lead;
    }

    /** Claude's quick classification (Tür, Sektör) from what the record carries; never overwrites a confirmed kind. */
    public function classify(SalesLead $lead): void
    {
        try {
            app(LeadClassifier::class)->apply($lead);
        } catch (Throwable $exception) {
            report($exception);
        }
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

    /**
     * Open cards whose next step is today or overdue, the oldest step first. The one source of "bugün yapılacak adım" for
     * Satış, Gelen kutusu, Takvim and the drafts (Satış Düzeni 1.3): no irrelevant or archived record, and "today" ends
     * at midnight in Istanbul, not in UTC.
     *
     * @return Builder<SalesLead>
     */
    public function due(): Builder
    {
        return SalesLead::query()->open()->relevant()->whereNotNull('next_step_at')->where('next_step_at', '<=', SalesLead::endOfToday())->orderBy('next_step_at');
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
