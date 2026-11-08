<?php

namespace App\Services\Inbox;

use App\Models\Brand;
use App\Models\Customer;
use App\Models\SalesLead;
use App\Models\SocialThread;
use App\Models\WhatsAppConversation;
use App\Models\WhatsAppMessage;
use App\Services\Sales\CustomerSignals;
use App\Services\Sales\SalesLeads;
use App\Services\WhatsApp\WhatsAppConnection;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;

/**
 * Gelen kutusu (Moximu büyüme sistemi, Aşama 2; yakup 2026-11-07): one queue for everything that waits for a word
 * from us. WhatsApp chats where they wrote last (prospects and customers), Instagram / Facebook messages and comments
 * waiting for a reply, and the sales records whose next step is today, all read from their own tables. Four views
 * answer "kime cevap borçluyum", "bugün ne yapacağım", "müşteriler ne istedi" and "hepsi"; every line leaves the
 * queue with Yapıldı, Sonra (a date) or İlgisiz. The goal is a queue at zero by the end of the day.
 */
final class InboxQueue
{
    public const array VIEWS = ['sohbetler' => 'Sohbetler', 'borclu' => 'Cevap borçlu', 'adimlar' => 'Bugünün adımları', 'musteriler' => 'Müşterilerden', 'hepsi' => 'Hepsi'];

    /**
     * The left rail (yakup 2026-11-08, Pazarlama's format): the channels a line can belong to, each with the record
     * sources it gathers. "Tümü" ('') is every line, risks included.
     */
    public const array CHANNELS = [
        'whatsapp' => ['label' => 'WhatsApp', 'sources' => ['whatsapp']],
        'instagram' => ['label' => 'Instagram DM', 'sources' => ['instagram', 'facebook']],
        'form' => ['label' => 'Web sitesi formu', 'sources' => ['form', 'meta_form']],
        'manuel' => ['label' => 'Manuel giriş', 'sources' => ['phone', 'link', 'other']],
        'referans' => ['label' => 'Referanslar · AFC', 'sources' => ['referral', 'afc']],
    ];

    /** Chats listed in "Sohbetler" (WhatsApp Web order: the newest message first); a search reaches every chat. */
    public const int CHATS = 150;

    /** A chat older than this (counted from the newest message the inbox holds, since backups come in batches) is history, not a debt. */
    public const int DAYS = 30;

    public function __construct(private SalesLeads $leads, private WhatsAppConnection $connection, private CustomerSignals $signals) {}

    /** @return Collection<int, InboxItem> every line of the queue, newest activity first */
    public function items(): Collection
    {
        $items = collect();
        $seenLeads = [];
        foreach ($this->conversations() as $item) {
            $items->push($item);
            if ($item->lead !== null) {
                $seenLeads[$item->lead->id] = true;
            }
        }
        foreach ($this->threads() as $item) {
            $items->push($item);
            if ($item->lead !== null) {
                $seenLeads[$item->lead->id] = true;
            }
        }
        foreach ($this->leads->due()->relevant()->with('customer')->get() as $lead) {
            if (isset($seenLeads[$lead->id])) {
                continue;
            }
            $items->push($this->stepItem($lead));
        }
        // Müşteri dönemi (Aşama 3): the four risk signals land here as work on the brand.
        foreach ($this->signals->all() as $row) {
            foreach ($row['signals'] as $signal) {
                $items->push($this->riskItem($row['brand'], $signal));
            }
        }

        return $items->sortByDesc(fn (InboxItem $item): string => ($item->at?->format('Y-m-d H:i:s') ?? '0000').'|'.$item->key)->values();
    }

    /** @return Collection<int, InboxItem> the lines of one view, in the order to work them */
    public function view(string $view, ?Collection $items = null): Collection
    {
        $items ??= $this->items();
        $rows = $items->filter(fn (InboxItem $item): bool => $item->in($view))->values();
        if ($view === 'adimlar') {
            // Steps: the oldest (most overdue) first, as on the old Bugün list.
            return $rows->sortBy(fn (InboxItem $item): string => ($item->lead?->next_step_at?->format('Y-m-d H:i:s') ?? '9999').'|'.$item->key)->values();
        }

        return $rows;
    }

    /** @return array<string, int> how many lines each view holds */
    public function counts(?Collection $items = null): array
    {
        $items ??= $this->items();
        $counts = [];
        foreach (array_keys(self::VIEWS) as $view) {
            if ($view === 'sohbetler') {
                continue;
            }
            $counts[$view] = $items->filter(fn (InboxItem $item): bool => $item->in($view))->count();
        }

        return $counts;
    }

    /** The rail channel a record source belongs to (no source or an unknown one = typed in by hand). */
    public static function channelOf(?string $source): string
    {
        foreach (self::CHANNELS as $key => $channel) {
            if (in_array($source, $channel['sources'], true)) {
                return $key;
            }
        }

        return 'manuel';
    }

    /**
     * What the rail shows per channel: lines that need a word from us (owed answers and due steps) and the records the
     * channel brought this month (Pazarlama counts the same records by where they heard of us).
     *
     * @param  Collection<int, InboxItem>  $items
     * @return array<string, array{open: int, month: int}>
     */
    public function channelCounts(Collection $items): array
    {
        $since = CarbonImmutable::now('Europe/Istanbul')->startOfMonth()->utc();
        $bySource = SalesLead::query()->relevant()->where('created_at', '>=', $since)
            ->selectRaw('source, count(*) as n')->groupBy('source')->pluck('n', 'source');
        $referred = SalesLead::query()->relevant()->where('created_at', '>=', $since)
            ->whereIn('heard_from', ['referral', 'afc'])->whereNotIn('source', self::CHANNELS['referans']['sources'])->count();
        $open = $items->filter(fn (InboxItem $item): bool => $item->owed || $item->due);
        $counts = ['' => ['open' => $open->count(), 'month' => (int) $bySource->sum()]];
        foreach (array_keys(self::CHANNELS) as $key) {
            $counts[$key] = ['open' => $open->filter(fn (InboxItem $item): bool => $item->inChannel($key))->count(), 'month' => 0];
        }
        foreach ($bySource as $source => $n) {
            $counts[self::channelOf($source === '' ? null : (string) $source)]['month'] += (int) $n;
        }
        $counts['referans']['month'] += $referred;

        return $counts;
    }

    /** What the menu badge shows: lines that need a word from us today (owed answers and today's steps). */
    public function openCount(): int
    {
        return $this->items()->filter(fn (InboxItem $item): bool => $item->owed || $item->due)->count();
    }

    /** One line by its key ("wa-12", "sosyal-5", "adim-9"), whether or not it is still in the queue; null when gone. */
    public function find(string $key): ?InboxItem
    {
        $parsed = InboxItem::parse($key);
        if ($parsed === null) {
            return null;
        }
        [$type, $id] = $parsed;
        if ($type === InboxItem::WHATSAPP) {
            $inboxId = $this->connection->integration()?->id;
            $conversation = $inboxId !== null ? WhatsAppConversation::query()->where('integration_id', $inboxId)->find($id) : null;

            return $conversation !== null ? $this->conversationItem($conversation, $this->leadsFor(collect([$conversation]))) : null;
        }
        if ($type === InboxItem::SOCIAL) {
            $thread = SocialThread::query()->onEnabledAccount()->with('account')->find($id);

            return $thread !== null ? $this->threadItem($thread) : null;
        }
        if ($type === InboxItem::RISK) {
            $brand = Brand::query()->with('customer')->find($id);
            $signal = $brand !== null ? collect($this->signals->raw($brand))->first(fn (array $s): bool => $s['key'] === $parsed[2] && $s['period'] === $parsed[3]) : null;

            return $signal !== null ? $this->riskItem($brand, $signal) : null;
        }
        $lead = SalesLead::query()->find($id);

        return $lead !== null ? $this->stepItem($lead) : null;
    }

    /** @param  array{key: string, period: string, label: string, detail: string, done: string}  $signal */
    private function riskItem(Brand $brand, array $signal): InboxItem
    {
        $lead = SalesLead::query()->where('brand_id', $brand->id)->orderByDesc('id')->first()
            ?? ($brand->customer_id !== null ? SalesLead::query()->where('customer_id', $brand->customer_id)->orderByDesc('id')->first() : null);

        return new InboxItem(
            key: InboxItem::riskKey((int) $brand->id, $signal['key'], $signal['period']), type: InboxItem::RISK, id: (int) $brand->id,
            title: $brand->name, subtitle: 'Müşteri'.($lead?->kindLabel() !== null ? ' · '.$lead->kindLabel() : ''), channel: 'Müşteri dönemi',
            preview: $signal['label'], at: CarbonImmutable::now('Europe/Istanbul')->startOfDay(),
            owed: false, due: true, late: false, fromCustomer: true,
            lead: $lead, customer: $brand->customer, brand: $brand, signal: $signal,
        );
    }

    /**
     * "Sohbetler" (yakup 2026-11-08: "tek ekran, WhatsApp gibi"): every chat of the inbox line, the newest message
     * first, with the last message (ours too) as the preview. A search matches the name or the number.
     *
     * @return Collection<int, InboxItem>
     */
    public function chats(string $search = ''): Collection
    {
        $inboxId = $this->connection->integration()?->id;
        if ($inboxId === null) {
            return collect();
        }
        $query = WhatsAppConversation::query()->where('integration_id', $inboxId)->whereNotNull('last_message_at');
        $search = trim($search);
        if ($search !== '') {
            $digits = preg_replace('/[^0-9]/', '', $search);
            $query->where(function ($q) use ($search, $digits): void {
                $q->whereRaw('lower(contact_name) like ?', ['%'.mb_strtolower($search).'%']);
                if (strlen((string) $digits) >= 3) {
                    $q->orWhere('contact_id', 'like', '%'.$digits.'%');
                }
            });
        }
        $conversations = $query->orderByDesc('last_message_at')->orderByDesc('id')->limit(self::CHATS)->get();
        if ($conversations->isEmpty()) {
            return collect();
        }
        $leads = $this->leadsFor($conversations);
        $latest = WhatsAppMessage::query()->whereIn('conversation_id', $conversations->pluck('id'))
            ->selectRaw('max(id) as id')->groupBy('conversation_id')->pluck('id');
        $previews = [];
        foreach (WhatsAppMessage::query()->whereKey($latest)->get(['conversation_id', 'body', 'direction']) as $row) {
            $previews[$row->conversation_id] = ($row->direction === 'outgoing' ? 'Sen: ' : '').self::oneLine((string) $row->body);
        }

        return $conversations->map(fn (WhatsAppConversation $c): InboxItem => $this->conversationItem($c, $leads, $previews[$c->id] ?? '', $c->last_message_at))->values();
    }

    /** How many chats the inbox line holds (the "Sohbetler" tab count). */
    public function chatCount(): int
    {
        $inboxId = $this->connection->integration()?->id;

        return $inboxId === null ? 0 : WhatsAppConversation::query()->where('integration_id', $inboxId)->whereNotNull('last_message_at')->count();
    }

    /** @return Collection<int, InboxItem> */
    private function conversations(): Collection
    {
        $inboxId = $this->connection->integration()?->id;
        if ($inboxId === null) {
            return collect();
        }
        $newest = WhatsAppConversation::query()->where('integration_id', $inboxId)->max('last_message_at');
        $since = ($newest !== null ? CarbonImmutable::parse($newest) : CarbonImmutable::now())->subDays(self::DAYS);
        $conversations = WhatsAppConversation::query()->where('integration_id', $inboxId)
            ->whereNull('done_at')->whereNull('opted_out_at')->whereNotNull('last_incoming_at')
            ->whereColumn('last_incoming_at', '>=', 'last_message_at')
            ->where('last_incoming_at', '>=', $since)
            ->where(fn ($q) => $q->whereNull('snoozed_until')->orWhere('snoozed_until', '<=', now()))
            ->orderByDesc('last_incoming_at')->limit(200)->get();
        if ($conversations->isEmpty()) {
            return collect();
        }
        $leads = $this->leadsFor($conversations);
        $previews = [];
        $latest = WhatsAppMessage::query()->whereIn('conversation_id', $conversations->pluck('id'))->where('direction', 'incoming')
            ->selectRaw('max(id) as id')->groupBy('conversation_id')->pluck('id');
        foreach (WhatsAppMessage::query()->whereKey($latest)->get(['conversation_id', 'body']) as $row) {
            $previews[$row->conversation_id] = self::oneLine((string) $row->body);
        }
        $items = collect();
        foreach ($conversations as $conversation) {
            $item = $this->conversationItem($conversation, $leads, $previews[$conversation->id] ?? '');
            // A record marked İlgisiz leaves the queue; a new message reopens the record (readConversation) and brings it back.
            if ($item->lead?->isIrrelevant()) {
                continue;
            }
            $items->push($item);
        }

        return $items;
    }

    /**
     * The records behind the chats: the chat's own card, or the customer's record (the customer's name comes with it).
     *
     * @param  Collection<int, WhatsAppConversation>  $conversations
     * @return array{leads: Collection<int, SalesLead>, customerLeads: Collection<int, SalesLead>, customers: Collection<int, Customer>}
     */
    private function leadsFor(Collection $conversations): array
    {
        $customerIds = $conversations->pluck('customer_id')->filter()->unique()->values();

        return [
            'leads' => SalesLead::query()->with('customer')->whereIn('conversation_id', $conversations->pluck('id'))->get()->keyBy('conversation_id'),
            'customerLeads' => $customerIds->isEmpty() ? collect() : SalesLead::query()->whereIn('customer_id', $customerIds)->orderBy('id')->get()->keyBy('customer_id'),
            'customers' => $customerIds->isEmpty() ? collect() : Customer::query()->whereKey($customerIds)->get()->keyBy('id'),
        ];
    }

    /** @param  array{leads: Collection<int, SalesLead>, customerLeads: Collection<int, SalesLead>, customers: Collection<int, Customer>}  $leads */
    private function conversationItem(WhatsAppConversation $conversation, array $leads, ?string $preview = null, ?CarbonInterface $at = null): InboxItem
    {
        $lead = $leads['leads'][$conversation->id] ?? ($conversation->customer_id !== null ? ($leads['customerLeads'][$conversation->customer_id] ?? null) : null);
        $customer = $conversation->customer_id !== null ? ($leads['customers'][$conversation->customer_id] ?? Customer::query()->find($conversation->customer_id)) : $lead?->customer;
        if ($preview === null) {
            $preview = self::oneLine((string) WhatsAppMessage::query()->where('conversation_id', $conversation->id)->where('direction', 'incoming')->orderByDesc('id')->value('body'));
        }
        $title = $customer?->name ?? $lead?->title() ?? (filled($conversation->contact_name) ? (string) $conversation->contact_name : self::phoneLabel($conversation->contact_id));
        $owed = $conversation->done_at === null && $conversation->last_incoming_at !== null && ($conversation->last_message_at === null || $conversation->last_incoming_at->gte($conversation->last_message_at));

        return new InboxItem(
            key: 'wa-'.$conversation->id, type: InboxItem::WHATSAPP, id: (int) $conversation->id,
            title: $title, subtitle: self::subtitle($lead, $customer), channel: 'WhatsApp', preview: $preview,
            at: $at ?? $conversation->last_incoming_at ?? $conversation->last_message_at,
            owed: $owed, due: (bool) $lead?->isDue(), late: self::late($lead),
            fromCustomer: $conversation->customer_id !== null || (bool) $lead?->isCustomer(),
            lead: $lead, customer: $customer, conversation: $conversation,
        );
    }

    /** @return Collection<int, InboxItem> */
    private function threads(): Collection
    {
        $threads = SocialThread::query()->onEnabledAccount()->with('account')->where('status', SocialThread::OPEN)->where('last_direction', 'in')
            ->where(fn ($q) => $q->whereNull('snoozed_until')->orWhere('snoozed_until', '<=', now()))
            ->orderByDesc('last_at')->limit(100)->get();
        $items = collect();
        foreach ($threads as $thread) {
            $item = $this->threadItem($thread);
            if ($item->lead?->isIrrelevant()) {
                continue;
            }
            $items->push($item);
        }

        return $items;
    }

    private function threadItem(SocialThread $thread): InboxItem
    {
        $lead = $thread->sales_lead_id !== null ? SalesLead::query()->find($thread->sales_lead_id) : null;
        $customer = $lead?->customer;
        $platform = $thread->account?->platformLabel() ?? 'Sosyal medya';

        return new InboxItem(
            key: 'sosyal-'.$thread->id, type: InboxItem::SOCIAL, id: (int) $thread->id,
            title: $customer?->name ?? $lead?->title() ?? ($thread->author_name ?: 'Bilinmeyen kişi'),
            subtitle: self::subtitle($lead, $customer) ?: ($thread->kind === SocialThread::COMMENT && $thread->post_caption ? 'Gönderi: '.mb_substr((string) $thread->post_caption, 0, 80) : ''),
            channel: $platform.' '.mb_strtolower($thread->kindLabel()).($thread->kind === SocialThread::COMMENT ? 'u' : 'ı'),
            preview: self::oneLine((string) $thread->last_text), at: $thread->last_in_at ?? $thread->last_at,
            owed: $thread->waitsForUs(), due: (bool) $lead?->isDue(), late: self::late($lead),
            fromCustomer: (bool) $lead?->isCustomer(), lead: $lead, customer: $customer, thread: $thread,
        );
    }

    private function stepItem(SalesLead $lead): InboxItem
    {
        $customer = $lead->customer;
        $text = self::oneLine((string) $lead->draft);
        $form = in_array($lead->source, ['form', 'meta_form'], true);

        return new InboxItem(
            key: 'adim-'.$lead->id, type: InboxItem::STEP, id: (int) $lead->id,
            title: $customer?->name ?? $lead->title(), subtitle: self::subtitle($lead, $customer),
            channel: $form ? $lead->sourceLabel() : 'Adım',
            preview: ($lead->next_step ?: 'Sonraki adım').($text !== '' ? ' · '.$text : ''),
            at: $lead->next_step_at,
            owed: $lead->awaitsUs() || ($form && $lead->stage === 'new'), due: $lead->isDue(), late: self::late($lead),
            fromCustomer: $lead->isCustomer(), lead: $lead, customer: $customer,
        );
    }

    private static function subtitle(?SalesLead $lead, ?Customer $customer): string
    {
        if ($lead === null) {
            return $customer !== null ? 'Müşteri' : '';
        }
        $parts = [];
        if ($lead->isCustomer()) {
            $parts[] = 'Müşteri';
        } elseif ($lead->lifecycle === 'former') {
            $parts[] = 'Eski müşteri';
        } else {
            $parts[] = $lead->stageLabel();
        }
        if ($lead->kindLabel() !== null) {
            $parts[] = $lead->kindLabel();
        }
        $sub = $lead->subtitle();
        if ($sub !== '' && $sub !== $lead->title()) {
            $parts[] = $sub;
        }

        return implode(' · ', $parts);
    }

    private static function late(?SalesLead $lead): bool
    {
        return $lead !== null && $lead->isDue() && $lead->next_step_at !== null
            && $lead->next_step_at->timezone('Europe/Istanbul')->toDateString() < now('Europe/Istanbul')->toDateString();
    }

    public static function oneLine(string $text, int $max = 160): string
    {
        return mb_substr(trim((string) preg_replace('/\s+/u', ' ', $text)), 0, $max);
    }

    public static function phoneLabel(?string $number): string
    {
        return match (true) {
            filled($number) && ctype_digit((string) $number) => '+'.$number,
            filled($number) => 'Gizli numara',
            default => '—',
        };
    }
}
