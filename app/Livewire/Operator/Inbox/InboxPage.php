<?php

namespace App\Livewire\Operator\Inbox;

use App\Jobs\WhatsApp\GenerateWhatsAppSuggestion;
use App\Models\Brand;
use App\Models\Reminder;
use App\Models\SocialThread;
use App\Models\WhatsAppMessage;
use App\Services\Inbox\InboxItem;
use App\Services\Inbox\InboxQueue;
use App\Services\Sales\Agreements;
use App\Services\Sales\CustomerSignals;
use App\Services\Sales\SalesLeads;
use App\Services\Sales\SalesReplyWriter;
use App\Services\Social\SocialInbox;
use App\Services\WhatsApp\Backup\WhatsAppBackupImporter;
use App\Services\WhatsApp\WhatsAppConnection;
use App\Support\Roles;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Throwable;

/**
 * Gelen kutusu (Aşama 2, yakup 2026-11-07): the day's one queue. Left the lines (WhatsApp, Instagram / Facebook, forms,
 * today's steps), in the middle the chat and the ready text, right the record's card. Every line leaves with Yapıldı,
 * Sonra (a date) or İlgisiz; the old WhatsApp screen, Sosyal › Mesajlar, Satış › Bugün and Müşteri istekleri point here.
 * MoxDOP sends WhatsApp texts from the phone only; Instagram / Facebook replies go out from here inside their window.
 */
#[Layout('operator.layouts.app')]
#[Title('Gelen kutusu')]
final class InboxPage extends Component
{
    #[Url(as: 'gorunum', except: 'borclu')]
    public string $view = 'borclu';

    /** The selected line's key (wa-12 | sosyal-5 | adim-9). */
    #[Url(as: 'kayit', except: '')]
    public string $item = '';

    public string $message = '';

    /** The text in hand: the record's draft, the chat's suggestion or the thread's draft, editable before copying or sending. */
    public string $reply = '';

    /** The key the text in hand belongs to (a fresh draft replaces an untouched one). */
    public string $replyFor = '';

    public bool $laterOpen = false;

    public string $laterDate = '';

    /** How many whole days a WhatsApp chat is shown in the middle pane. */
    private const int MESSAGES = 14;

    public function mount(): void
    {
        abort_unless((bool) auth()->user()?->hasRole(Roles::ADMIN), 403);
        $this->message = (string) session('inbox_notice', '');
        $this->checkView();
    }

    public function updatedView(): void
    {
        if (! isset(InboxQueue::VIEWS[$this->view])) {
            $this->view = 'borclu';
        }
        $this->laterOpen = false;
        $this->resetErrorBag();
    }

    /** Keeps the view valid on every render without touching the open "Sonra" row. */
    private function checkView(): void
    {
        if (! isset(InboxQueue::VIEWS[$this->view])) {
            $this->view = 'borclu';
        }
    }

    public function select(string $key): void
    {
        $this->item = InboxItem::parse($key) !== null ? $key : '';
        $this->laterOpen = false;
        $this->resetErrorBag();
    }

    /** "Yapıldı": the answer went out (from the phone) or the step was taken; the record's next step is scheduled. */
    public function done(InboxQueue $queue, SalesLeads $leads): void
    {
        $item = $this->current($queue);
        $text = trim($this->reply) !== '' ? trim($this->reply) : null;
        if ($item->type === InboxItem::RISK && $item->brand !== null && $item->signal !== null) {
            // A customer-period signal: what "Yapıldı" means depends on the signal (report sent, contact made, reminded).
            $this->after(app(CustomerSignals::class)->handle($item->brand, $item->signal['key'], $item->signal['period'], auth()->user(), $text), $queue);

            return;
        }
        if ($item->conversation !== null) {
            $item->conversation->update(['done_at' => now(), 'snoozed_until' => null]);
        }
        if ($item->thread !== null) {
            $item->thread->forceFill(['status' => SocialThread::ANSWERED, 'draft_status' => null, 'snoozed_until' => null])->save();
        }
        $lead = $item->lead;
        if ($lead !== null && $lead->isOpen() && ! $lead->isCustomer()) {
            $leads->markSent($lead, $text, auth()->user());
        } elseif ($lead !== null) {
            // A customer: no follow-up rhythm, only the fact that we answered (their chat is the record's timeline).
            $lead->forceFill(['last_contact_at' => now()])->save();
            if ($item->thread !== null && $text !== null) {
                $leads->event($lead, 'message_out', $text, meta: ['by' => auth()->id(), 'manual' => true]);
            }
        }
        $this->after('Yapıldı.', $queue);
    }

    /** "Sonra": the line comes back on that day (the record's step moves there too; a customer's request becomes a task). */
    public function later(InboxQueue $queue, SalesLeads $leads): void
    {
        $item = $this->current($queue);
        $today = CarbonImmutable::now('Europe/Istanbul')->toDateString();
        $date = $this->laterDate !== '' ? $this->laterDate : CarbonImmutable::now('Europe/Istanbul')->addDay()->toDateString();
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) !== 1 || $date <= $today) {
            throw ValidationException::withMessages(['laterDate' => 'Yarından sonraki bir gün seç.']);
        }
        $at = CarbonImmutable::parse($date, 'Europe/Istanbul')->setTime(10, 0);
        if ($item->type === InboxItem::RISK && $item->brand !== null && $item->signal !== null) {
            app(CustomerSignals::class)->snooze($item->brand, $item->signal['key'], $item->signal['period'], $at, auth()->user());
            $this->laterDate = '';
            $this->laterOpen = false;
            $this->after($at->format('d.m.Y').' gününe bırakıldı; o sabah kutuya döner.', $queue);

            return;
        }
        $lead = $item->lead;
        if ($lead !== null && $lead->isOpen() && ! $lead->isCustomer()) {
            $leads->later($lead, $date, auth()->user());
        } elseif ($lead !== null && $lead->isCustomer() && $lead->isOpen()) {
            $lead->forceFill(['next_step' => $lead->next_step ?: 'Müşteriye dön', 'next_step_at' => $at->utc()])->save();
        }
        if ($item->conversation !== null) {
            $item->conversation->update(['snoozed_until' => $at->utc()]);
            if ($item->fromCustomer) {
                // As "Göreve çevir" did: on Takvim and the phone that morning.
                Reminder::query()->create([
                    'user_id' => auth()->id(), 'customer_id' => $item->conversation->customer_id, 'brand_id' => $lead?->brand_id ?? Brand::query()->where('customer_id', $item->conversation->customer_id)->orderBy('id')->value('id'),
                    'sales_lead_id' => $lead?->id, 'kind' => 'task', 'title' => 'Müşteri isteği: '.$item->title,
                    'notes' => $item->preview !== '' ? mb_substr($item->preview, 0, 140) : null, 'remind_at' => $at->utc(), 'repeat' => 'none',
                ]);
            }
        }
        if ($item->thread !== null) {
            $item->thread->forceFill(['snoozed_until' => $at->utc()])->save();
        }
        $this->laterDate = '';
        $this->laterOpen = false;
        $this->after($at->format('d.m.Y').' gününe bırakıldı; o sabah kutuya döner.', $queue);
    }

    /** "İlgisiz": a supplier, a job seeker, spam. The record is kept (and comes back by itself if they write again). */
    public function irrelevant(InboxQueue $queue, SalesLeads $leads): void
    {
        $item = $this->current($queue);
        if ($item->fromCustomer) {
            throw ValidationException::withMessages(['item' => 'Bir müşteri ilgisiz olamaz; isteği Yapıldı ya da Sonra ile kapat.']);
        }
        $lead = $item->lead;
        if ($lead === null && $item->conversation !== null) {
            $lead = $leads->fromConversation($item->conversation);
        }
        if ($lead === null && $item->thread !== null) {
            $lead = app(SocialInbox::class)->toLead($item->thread);
        }
        if ($lead !== null) {
            $leads->markIrrelevant($lead, auth()->user());
        }
        if ($item->conversation !== null) {
            $item->conversation->update(['done_at' => now(), 'snoozed_until' => null]);
        }
        if ($item->thread !== null) {
            $item->thread->forceFill(['status' => SocialThread::IGNORED, 'draft_status' => null, 'snoozed_until' => null])->save();
        }
        $this->after('İlgisiz olarak işlendi; yeniden yazarsa kutuya döner.', $queue);
    }

    /** Instagram / Facebook: the reply goes out from here (DM inside the 24-hour window). */
    public function send(InboxQueue $queue): void
    {
        $item = $this->current($queue);
        if ($item->thread === null) {
            throw ValidationException::withMessages(['reply' => 'WhatsApp mesajı telefondan gider; metni kopyala ya da WhatsApp\'ta aç.']);
        }
        $text = trim($this->reply);
        if ($text === '') {
            throw ValidationException::withMessages(['reply' => 'Gönderilecek metni yaz.']);
        }
        try {
            app(SocialInbox::class)->send($item->thread, $text);
        } catch (Throwable $e) {
            $item->thread->forceFill(['send_error' => mb_substr($e->getMessage(), 0, 480)])->save();
            throw ValidationException::withMessages(['reply' => 'Gönderilemedi: '.$e->getMessage()]);
        }
        if ($item->lead !== null && $item->lead->isOpen() && ! $item->lead->isCustomer()) {
            app(SalesLeads::class)->markSent($item->lead, null, auth()->user());
        }
        $this->after('Cevap gönderildi.', $queue);
    }

    /** "Hazır sosyal cevapları gönder": every ready Instagram / Facebook draft that may still be sent. */
    public function sendReady(): void
    {
        $result = app(SocialInbox::class)->sendAllReady();
        $this->message = $result['sent'].' cevap gönderildi'.($result['failed'] > 0 ? ', '.$result['failed'].' gönderilemedi (satırda sebebi yazıyor).' : '.');
    }

    /** "Claude yazsın": the text for this line is written in the background. */
    public function write(InboxQueue $queue, WhatsAppConnection $connection): void
    {
        $item = $this->current($queue);
        $this->reply = '';
        $this->replyFor = '';
        if ($item->thread !== null) {
            $item->thread->forceFill(['draft' => null])->save();
            SocialInbox::queueDraft($item->thread);
            $this->message = 'Claude cevabı yazıyor.';

            return;
        }
        $lead = $item->lead;
        if ($lead !== null && ! $lead->isCustomer()) {
            if ($lead->kind === null && $lead->suggestedKind() === null) {
                throw ValidationException::withMessages(['item' => 'Önce kayıt türünü seç (kartta); tür bilinmeden metin yazılmaz.']);
            }
            SalesReplyWriter::queue($lead);
            $this->message = 'Claude metni yazıyor; birkaç saniyede burada.';

            return;
        }
        if ($item->conversation !== null) {
            if (! $connection->integration()?->isActive()) {
                throw ValidationException::withMessages(['item' => 'Öneri için WhatsApp bağlantısı etkin olmalı.']);
            }
            $item->conversation->update(['suggestion_status' => 'requested', 'error_code' => null]);
            try {
                GenerateWhatsAppSuggestion::dispatch($item->conversation->id);
            } catch (Throwable $exception) {
                report($exception);
            }
            $this->message = 'Cevap önerisi hazırlanıyor.';

            return;
        }
        throw ValidationException::withMessages(['item' => 'Bu satır için yazılacak metin yok.']);
    }

    /** A chat with no record yet: open one (a prospect's card, or the customer's record). */
    public function openRecord(InboxQueue $queue, SalesLeads $leads): void
    {
        $item = $this->current($queue);
        $lead = $item->lead;
        if ($lead === null && $item->conversation !== null) {
            $lead = $item->customer !== null ? $leads->forCustomer($item->customer) : $leads->fromConversation($item->conversation);
        }
        if ($lead === null && $item->thread !== null) {
            $lead = app(SocialInbox::class)->toLead($item->thread);
        }
        if ($lead === null) {
            throw ValidationException::withMessages(['item' => 'Bu görüşme için kayıt açılamadı (kendi numaramız ya da grup).']);
        }
        $this->message = 'Kayıt açıldı: '.$lead->title().'.';
    }

    public function render(InboxQueue $queue, Agreements $agreements): View
    {
        $this->checkView();
        $items = $queue->items();
        $counts = $queue->counts($items);
        $rows = $queue->view($this->view, $items);
        $selected = null;
        if ($this->item !== '') {
            $selected = $items->firstWhere('key', $this->item) ?? $queue->find($this->item);
            if ($selected === null) {
                $this->item = '';
            }
        }
        $detail = $selected !== null ? $this->detail($selected, $agreements) : null;
        if ($selected !== null && $detail !== null && $this->replyFor !== $selected->key) {
            // A new line, or the text was just written: the text in hand follows the record.
            $this->reply = (string) ($detail['draft']['text'] ?? '');
            $this->replyFor = $selected->key;
        } elseif ($selected !== null && $detail !== null && trim($this->reply) === '' && ($detail['draft']['text'] ?? '') !== '') {
            $this->reply = (string) $detail['draft']['text'];
        }
        $owed = $items->filter(fn (InboxItem $i): bool => $i->owed);
        $oldest = $owed->map(fn (InboxItem $i) => $i->at)->filter()->sort()->first();

        return view('livewire.operator.inbox.inbox-page', [
            'rows' => $rows,
            'counts' => $counts,
            'selected' => $selected,
            'detail' => $detail,
            'strip' => [
                'owed' => $owed->count(),
                'late' => $items->filter(fn (InboxItem $i): bool => $i->late)->count(),
                'today' => $items->filter(fn (InboxItem $i): bool => $i->due && ! $i->late)->count(),
                'oldest' => $oldest !== null ? $this->age($oldest) : null,
            ],
            'readyCount' => SocialThread::query()->where('status', SocialThread::OPEN)->where('draft_status', 'ready')->whereNotNull('draft')->count(),
            'drafting' => $detail !== null && ($detail['draft']['busy'] ?? false),
            'today' => CarbonImmutable::now('Europe/Istanbul')->toDateString(),
            'tomorrow' => CarbonImmutable::now('Europe/Istanbul')->addDay()->toDateString(),
        ]);
    }

    /**
     * What the middle and right panes show for a line: the chat (or the record's timeline), the text in hand, the
     * WhatsApp link, the reply window, the record and (for a customer) the brand's agreement.
     *
     * @return array<string, mixed>
     */
    private function detail(InboxItem $item, Agreements $agreements): array
    {
        $lead = $item->lead;
        $messages = collect();
        $window = null;
        $waNumber = null;
        if ($item->conversation !== null) {
            $messages = WhatsAppMessage::query()->where('conversation_id', $item->conversation->id)->orderByDesc('sent_at')->orderByDesc('id')->limit(self::MESSAGES)->get()
                ->map(fn (WhatsAppMessage $m): array => ['in' => $m->direction === 'incoming', 'body' => (string) $m->body, 'at' => CarbonImmutable::instance($m->sent_at), 'type' => $m->message_type])
                ->reverse()->values();
            $live = $item->conversation->phone_number_id !== WhatsAppBackupImporter::LINE;
            $lastIn = $item->conversation->last_incoming_at;
            if ($live && $lastIn !== null) {
                $open = $lastIn->greaterThan(now()->subHours(24));
                $window = ['open' => $open, 'hours' => $open ? max(1, (int) ceil(now()->diffInHours($lastIn->copy()->addHours(24), false))) : 0];
            }
            $waNumber = ctype_digit((string) $item->conversation->contact_id) ? (string) $item->conversation->contact_id : $lead?->waNumber();
        } elseif ($item->thread !== null) {
            $messages = $item->thread->messages()->orderByDesc('sent_at')->limit(self::MESSAGES)->get()
                ->map(fn ($m): array => ['in' => $m->direction === 'in', 'body' => (string) $m->body, 'at' => CarbonImmutable::instance($m->sent_at), 'type' => 'text', 'who' => $m->author_name])
                ->reverse()->values();
            $waNumber = $lead?->waNumber();
        } elseif ($lead !== null) {
            $messages = app(SalesLeads::class)->timeline($lead, 8)
                ->map(fn (array $row): array => ['in' => $row['kind'] === 'message_in', 'body' => $row['body'], 'at' => $row['at'], 'type' => $row['kind'], 'kind' => $row['kind']])
                ->reverse()->values();
            $waNumber = $lead->waNumber();
        }
        $draft = $this->draft($item);
        $brand = null;
        $terms = null;
        if ($item->fromCustomer) {
            $brand = $item->brand ?? $lead?->brand ?? ($item->customer !== null ? Brand::query()->where('customer_id', $item->customer->id)->orderBy('id')->first() : null);
            $terms = $brand !== null ? $agreements->summary($brand) : null;
        }

        return [
            'messages' => $messages,
            'draft' => $draft,
            'waNumber' => $waNumber,
            'window' => $window,
            'brand' => $brand,
            'terms' => $terms,
            'opportunity' => $lead?->openOpportunity(),
            'findings' => $lead !== null && is_array($lead->site_scan) ? array_slice(array_values(array_filter((array) ($lead->site_scan['findings'] ?? []), 'is_string')), 0, 4) : [],
            'fromBackup' => $item->conversation !== null && $item->conversation->phone_number_id === WhatsAppBackupImporter::LINE,
        ];
    }

    /** @return array{text: string, status: string, busy: bool, note: string, wait: bool, source: string} */
    private function draft(InboxItem $item): array
    {
        $lead = $item->lead;
        if ($item->type === InboxItem::RISK) {
            return ['text' => '', 'status' => '', 'busy' => false, 'note' => '', 'wait' => false, 'source' => 'none'];
        }
        if ($item->thread !== null) {
            $status = (string) $item->thread->draft_status;

            return ['text' => (string) $item->thread->draft, 'status' => $status, 'busy' => in_array($status, ['queued', 'running'], true),
                'note' => (string) $item->thread->draft_note, 'wait' => false, 'source' => 'social'];
        }
        if ($lead !== null && ! $lead->isCustomer() && $lead->isOpen()) {
            $status = (string) $lead->draft_status;

            return ['text' => (string) $lead->draft, 'status' => $status, 'busy' => in_array($status, ['queued', 'running'], true),
                'note' => (string) $lead->draft_note, 'wait' => false, 'source' => 'lead'];
        }
        if ($item->conversation !== null) {
            $c = $item->conversation;
            $fresh = $c->suggestion_status === 'ready' && $c->suggested_revision === $c->revision;

            return ['text' => $fresh && $c->suggestion_action !== 'wait' ? (string) $c->suggestion : '', 'status' => (string) $c->suggestion_status,
                'busy' => in_array($c->suggestion_status, ['requested', 'running'], true), 'note' => $fresh ? (string) $c->summary : '',
                'wait' => $fresh && $c->suggestion_action === 'wait', 'source' => 'conversation'];
        }

        return ['text' => '', 'status' => '', 'busy' => false, 'note' => '', 'wait' => false, 'source' => 'none'];
    }

    private function current(InboxQueue $queue): InboxItem
    {
        $item = $this->item !== '' ? $queue->find($this->item) : null;
        if ($item === null) {
            throw ValidationException::withMessages(['item' => 'Önce listeden bir satır seç.']);
        }

        return $item;
    }

    /** After an action: say what happened and move to the next line of the view. */
    private function after(string $message, InboxQueue $queue): void
    {
        $this->message = $message;
        $this->reply = '';
        $this->replyFor = '';
        $this->resetErrorBag();
        $next = $queue->view($this->view)->first(fn (InboxItem $i): bool => $i->key !== $this->item);
        $this->item = $next?->key ?? '';
    }

    private function age(CarbonImmutable|CarbonInterface $at): string
    {
        $minutes = (int) $at->diffInMinutes(now());
        if ($minutes < 60) {
            return max(1, $minutes).' dk';
        }
        if ($minutes < 60 * 48) {
            return (int) round($minutes / 60).' sa';
        }

        return (int) round($minutes / 1440).' gün';
    }
}
