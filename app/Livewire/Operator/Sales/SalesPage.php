<?php

namespace App\Livewire\Operator\Sales;

use App\Models\Agreement;
use App\Models\AgreementPayment;
use App\Models\Brand;
use App\Models\Customer;
use App\Models\Reminder;
use App\Models\SalesLead;
use App\Models\SalesLeadEvent;
use App\Models\SalesOpportunity;
use App\Models\WhatsAppConversation;
use App\Models\WhatsAppMessage;
use App\Services\Integrations\WordPress\WordPressSiteBuilder;
use App\Services\Sales\Agreements;
use App\Services\Sales\LeadIntake;
use App\Services\Sales\SalesLeads;
use App\Services\Sales\SalesMonthReport;
use App\Services\Sales\SalesReplyWriter;
use App\Services\Sales\SalesShare;
use App\Support\Roles;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Throwable;

/**
 * Satış (yakup, 2026-11-04): one place for every prospect. Bugün = whose next step is today, with the text ready;
 * Adaylar = every open card by stage; İstekler = customers' chats waiting for an answer (one click makes a task);
 * Sonuçlar = where leads come from and what they bring. MoxDOP never sends a message.
 */
#[Layout('operator.layouts.app')]
#[Title('Satış')]
final class SalesPage extends Component
{
    public const array TABS = ['bugun' => 'Bugün', 'adaylar' => 'Adaylar', 'istekler' => 'Müşteri istekleri', 'sonuclar' => 'Sonuçlar'];

    #[Url(as: 'sekme', except: 'bugun')]
    public string $tab = 'bugun';

    #[Url(as: 'asama', except: '')]
    public string $stageFilter = '';

    #[Url(as: 'q', except: '')]
    public string $q = '';

    /** Adaylar: the ready views (yakup 2026-11-07), each answering one question ("kime cevap borçluyum?"…). */
    public const array VIEWS = [
        'acik' => 'Açık', 'borclu' => 'Cevap borçlu olduklarım', 'bugun' => 'Bugün / gecikmiş', 'adimsiz' => 'Adımsız',
        'soguyan' => 'Soğuyanlar', 'teklif' => 'Teklif verilenler', 'musteriler' => 'Müşteriler', 'kaybedilen' => 'Kaybedilen',
    ];

    public const array SORTS = ['oncelik' => 'Önce yapılacaklar', 'son_temas' => 'Son temas', 'teklif' => 'Teklif tutarı', 'bekleyen' => 'Aşamada en uzun bekleyen'];

    /** A card with no talk for this many days is cooling down. */
    public const int COLD_DAYS = 10;

    #[Url(as: 'gorunum', except: 'acik')]
    public string $view = 'acik';

    #[Url(as: 'sirala', except: 'oncelik')]
    public string $sort = 'oncelik';

    /** The open dialog on the Adaylar / Bugün rows: touch, note, offer or close ('' = none), and its card. */
    public string $dialog = '';

    public int $dialogLead = 0;

    /** @var array{channel: string, body: string, next: string, date: string, close: string} */
    public array $touch = ['channel' => 'phone', 'body' => '', 'next' => '', 'date' => '', 'close' => ''];

    public string $noteText = '';

    public string $offerAmount = '';

    public string $offerService = '';

    public string $closeReason = '';

    public string $closeNote = '';

    /** Sonuçlar: the month of the report (Y-m); empty = this month. */
    #[Url(as: 'ay', except: '')]
    public string $month = '';

    public bool $adding = false;

    /** @var array{name: string, company: string, city: string, phone: string, website: string, source: string, note: string} */
    public array $form = ['name' => '', 'company' => '', 'city' => '', 'phone' => '', 'website' => '', 'source' => 'phone', 'note' => ''];

    public string $message = '';

    public bool $pasting = false;

    public string $paste = '';

    public string $pasteNote = '';

    public function mount(): void
    {
        $this->message = (string) session('sales_notice', '');
        $quick = (string) session('sales_quick', '');
        if ($quick !== '') {
            $this->adding = true;
            $this->form['note'] = mb_substr($quick, 0, 1000);
        }
    }

    public function updatedTab(): void
    {
        if (! isset(self::TABS[$this->tab])) {
            $this->tab = 'bugun';
        }
    }

    public function quickAdd(SalesLeads $leads): void
    {
        $this->resetValidation();
        try {
            $lead = $leads->quickAdd($this->form, auth()->user());
        } catch (ValidationException $exception) {
            $this->addError('form', (string) collect($exception->errors())->flatten()->first());

            return;
        }
        $this->form = ['name' => '', 'company' => '', 'city' => '', 'phone' => '', 'website' => '', 'source' => 'phone', 'note' => ''];
        $this->adding = false;
        $this->redirectRoute('operator.sales.lead', ['lead' => $lead->id], navigate: true);
    }

    /** "Gönderdim" on a Bugün row: the text went out from the phone; the next step is scheduled. */
    public function markSent(int $id, SalesLeads $leads): void
    {
        $lead = SalesLead::query()->findOrFail($id);
        $leads->markSent($lead, filled($lead->draft) ? (string) $lead->draft : null, auth()->user());
        $this->say($lead->title().': gönderildi olarak işlendi; sonraki adım '.$lead->next_step_at?->timezone('Europe/Istanbul')->format('d.m.Y').'.');
    }

    /** "Sohbeti yapıştır": a chat copied from WhatsApp opens (or finds) the card and Claude answers at once. */
    public function pasteChat(SalesShare $share, SalesLeads $leads, SalesReplyWriter $writer): void
    {
        $this->resetErrorBag('paste');
        if (trim($this->paste) === '') {
            $this->addError('paste', 'Sohbeti yapıştır.');

            return;
        }
        $result = $share->importChat(mb_substr(trim($this->paste), 0, 400_000), '', auth()->user());
        $lead = $result['lead'];
        if ($lead === null || ! str_contains($result['notice'], 'mesaj karta işlendi')) {
            $this->addError('paste', $result['notice']);

            return;
        }
        if (trim($this->pasteNote) !== '') {
            $leads->event($lead, 'note', mb_substr(trim($this->pasteNote), 0, 4000), meta: ['by' => auth()->id(), 'for_ai' => true]);
        }
        if ((int) ini_get('max_execution_time') > 0) {
            @set_time_limit(150); // web only: the CLI (queue, tests) has no limit and must keep none
        }
        $ok = $writer->write($lead->id, 50);
        $this->paste = '';
        $this->pasteNote = '';
        session()->flash('sales_notice', $result['notice'].($ok ? ' Claude cevabı yazdı.' : ' Claude yazamadı; kartta "Yeniden yaz" ile dene.'));
        $this->redirectRoute('operator.sales.lead', ['lead' => $lead->id], navigate: true);
    }

    /** "Yaz" on a Bugün row without a text: Claude writes it in the background. */
    public function draftFor(int $id): void
    {
        $lead = SalesLead::query()->findOrFail($id);
        SalesReplyWriter::queue($lead);
        $this->say($lead->title().': Claude yazıyor, birkaç saniye içinde burada.');
    }

    /** "Bugün değil": the step moves to tomorrow morning. */
    public function snooze(int $id, SalesLeads $leads): void
    {
        $lead = SalesLead::query()->findOrFail($id);
        $leads->setNextStep($lead, (string) $lead->next_step, CarbonImmutable::now('Europe/Istanbul')->addDay()->toDateString(), auth()->user());
        $this->say($lead->title().' yarına alındı.');
    }

    public function setStage(int $id, string $stage, SalesLeads $leads): void
    {
        $lead = SalesLead::query()->findOrFail($id);
        if ($stage === 'lost') {
            // Closing always asks why (the month report's loss reasons are only as good as this answer).
            $this->openDialog('close', $id);

            return;
        }
        $leads->setStage($lead, $stage, null, auth()->user());
        $this->say($lead->title().': '.$lead->fresh()->stageLabel().'.');
    }

    /** Opens one of the row dialogs (Temas kaydet, Not, Teklif, Kapat) for a card, filled with what it has. */
    public function openDialog(string $kind, int $id): void
    {
        if (! in_array($kind, ['touch', 'note', 'offer', 'close'], true)) {
            return;
        }
        $lead = SalesLead::query()->findOrFail($id);
        $this->resetValidation();
        $this->dialog = $kind;
        $this->dialogLead = $lead->id;
        $this->touch = ['channel' => 'phone', 'body' => '', 'next' => '', 'date' => CarbonImmutable::now('Europe/Istanbul')->addDays(2)->toDateString(), 'close' => ''];
        $this->noteText = (string) $lead->notes;
        $this->offerAmount = $lead->offer_amount !== null ? (string) $lead->offer_amount : '';
        $this->offerService = (string) $lead->offer_service;
        $this->closeReason = '';
        $this->closeNote = '';
    }

    public function closeDialog(): void
    {
        $this->dialog = '';
        $this->dialogLead = 0;
        $this->resetValidation();
    }

    public function saveTouch(SalesLeads $leads): void
    {
        $lead = SalesLead::query()->findOrFail($this->dialogLead);
        $leads->logTouch($lead, $this->touch, auth()->user());
        $fresh = $lead->fresh();
        $this->closeDialog();
        $this->say($fresh->isOpen()
            ? $lead->title().': görüşme işlendi. Sonraki adım '.$fresh->next_step.' · '.$fresh->next_step_at?->timezone('Europe/Istanbul')->format('d.m.Y').'.'
            : $lead->title().': görüşme işlendi, kart kapandı.');
    }

    /** The card's pinned note (the one line shown under the name in Adaylar). */
    public function saveNote(): void
    {
        $lead = SalesLead::query()->findOrFail($this->dialogLead);
        $note = trim($this->noteText);
        $lead->update(['notes' => $note !== '' ? mb_substr($note, 0, SalesLeads::LIMITS['notes']) : null]);
        $this->closeDialog();
        $this->say($lead->title().': not kaydedildi.');
    }

    public function saveOffer(SalesLeads $leads): void
    {
        $lead = SalesLead::query()->findOrFail($this->dialogLead);
        $digits = preg_replace('/\D+/', '', $this->offerAmount) ?? '';
        $leads->setOffer($lead, $digits !== '' ? (int) $digits : null, $this->offerService !== '' ? $this->offerService : null, auth()->user());
        $this->closeDialog();
        $this->say($lead->title().': teklif kaydedildi.');
    }

    /** A suggested offer (read from a sent text) is right as it is. */
    public function confirmOffer(int $id, SalesLeads $leads): void
    {
        $lead = SalesLead::query()->findOrFail($id);
        if ($lead->offer_amount !== null) {
            $leads->setOffer($lead, $lead->offer_amount, $lead->offer_service, auth()->user());
            $this->say($lead->title().': teklif onaylandı.');
        }
    }

    public function saveClose(SalesLeads $leads): void
    {
        $lead = SalesLead::query()->findOrFail($this->dialogLead);
        if (! isset(SalesLead::LOST_REASONS[$this->closeReason])) {
            $this->addError('closeReason', 'Neden kapandığını seç.');

            return;
        }
        $leads->close($lead, $this->closeReason, $this->closeNote, auth()->user());
        $this->closeDialog();
        $this->say($lead->title().' kapandı ('.SalesLead::LOST_REASONS[$this->closeReason].').');
    }

    /**
     * Müşteriler: one row per served brand with its commercial state (the agreement, this month's tick, what is late,
     * the open opportunity), and the four numbers above them.
     *
     * @return array{rows: list<array<string, mixed>>, strip: array{mrr: int, offers: int, won_month: int, overdue: int, overdue_sum: int}}
     */
    private function customerTable(Agreements $agreements): array
    {
        $brands = Brand::query()->with('customer:id,name,status,monthly_fee')->operational()->orderBy('name')->get();
        $leads = SalesLead::query()->whereIn('brand_id', $brands->pluck('id'))->orderByDesc('id')->get()->keyBy('brand_id');
        $openOpps = SalesOpportunity::query()->where('status', 'open')->whereIn('lead_id', $leads->pluck('id'))->get()->keyBy('lead_id');
        $rows = [];
        $overdueSum = 0;
        $overdueCount = 0;
        foreach ($brands as $brand) {
            $summary = $agreements->summary($brand);
            $lead = $leads[$brand->id] ?? null;
            $thisMonth = $summary['payments']->firstWhere('period', now('Europe/Istanbul')->format('Y-m'));
            $late = $summary['payments']->filter(fn ($p): bool => $p->isOverdue());
            $overdueSum += (int) $late->sum('amount');
            $overdueCount += $late->count();
            $rows[] = [
                'brand' => $brand, 'customer' => $brand->customer, 'lead' => $lead, 'kind' => $lead?->kindLabel(), 'agreement' => $summary['agreement'],
                'this_month' => $thisMonth, 'late' => $late->count(), 'late_days' => $summary['late_days'], 'next_due' => $summary['next_due'], 'risks' => $summary['risks'],
                'opportunity' => $lead !== null ? ($openOpps[$lead->id] ?? null) : null,
            ];
        }
        usort($rows, fn (array $a, array $b): int => [$b['late'], (int) ($b['agreement']?->monthlyValue() ?? 0)] <=> [$a['late'], (int) ($a['agreement']?->monthlyValue() ?? 0)]);
        $offers = SalesLead::query()->open()->relevant()->where(fn ($q) => $q->whereNotNull('offer_amount')->orWhereIn('stage', ['priced', 'thinking']));

        return ['rows' => $rows, 'strip' => [
            'mrr' => $agreements->mrr(), 'offers' => (int) (clone $offers)->sum('offer_amount'), 'won_month' => $agreements->wonThisMonth(),
            'overdue' => $overdueCount, 'overdue_sum' => $overdueSum,
        ]];
    }

    /** "Yeni fırsat" on a customer row: the customer's sales record (made on first need) opens with the form. */
    public function opportunityFor(int $brandId, SalesLeads $leads): void
    {
        $brand = Brand::query()->with('customer')->findOrFail($brandId);
        abort_if($brand->customer === null, 404);
        $lead = $leads->forCustomer($brand->customer, $brand);
        session()->flash('sales_notice', $brand->name.': fırsatı "Fırsatlar" kartından aç.');
        $this->redirectRoute('operator.sales.lead', ['lead' => $lead->id], navigate: true);
    }

    /** The month's tick from the customer row. */
    public function togglePaid(int $paymentId, Agreements $agreements): void
    {
        $payment = AgreementPayment::query()->findOrFail($paymentId);
        $payment->isPaid() ? $agreements->unpay($payment) : $agreements->markPaid($payment);
        $this->say($payment->agreement?->brand?->name.' · '.$payment->periodLabel().': '.($payment->fresh()->isPaid() ? 'ödendi' : 'ödenmedi').'.');
    }

    /** "Konuşmaları tara": every WhatsApp chat without a card gets one. */
    public function scan(SalesLeads $leads): void
    {
        abort_unless(auth()->user()?->hasRole(Roles::ADMIN), 403);
        $created = $leads->scanConversations();
        $this->say($created > 0 ? $created.' görüşmeden kart açıldı.' : 'Kartı olmayan görüşme kalmadı.');
    }

    /** A customer's request becomes a task: tomorrow 10:00 on Takvim and the phone. */
    public function toTask(int $conversationId, SalesLeads $leads): void
    {
        $conversation = WhatsAppConversation::query()->whereNotNull('customer_id')->findOrFail($conversationId);
        $customer = Customer::query()->find($conversation->customer_id);
        $last = WhatsAppMessage::query()->where('conversation_id', $conversation->id)->where('direction', 'incoming')->orderByDesc('sent_at')->first();
        $snippet = mb_substr(trim((string) preg_replace('/\s+/u', ' ', (string) $last?->body)), 0, 140);
        $brandId = Brand::query()->where('customer_id', $conversation->customer_id)->orderBy('id')->value('id');
        Reminder::query()->create([
            'user_id' => auth()->id(), 'customer_id' => $conversation->customer_id, 'brand_id' => $brandId,
            'title' => 'Müşteri isteği: '.($customer?->name ?? $conversation->contact_name ?? $conversation->contact_id),
            'notes' => $snippet !== '' ? $snippet : null, 'remind_at' => CarbonImmutable::now('Europe/Istanbul')->addDay()->setTime(10, 0)->utc(), 'repeat' => 'none', 'kind' => 'task',
        ]);
        $conversation->update(['done_at' => now()]);
        $this->say('Görev açıldı: yarın 10:00\'da Takvim\'de ve telefonda.');
    }

    public function answered(int $conversationId): void
    {
        WhatsAppConversation::query()->whereNotNull('customer_id')->findOrFail($conversationId)->update(['done_at' => now()]);
        $this->say('Cevaplandı olarak işlendi.');
    }

    public string $formSite = '';

    /** Sonuçlar › Site formu: the chosen site's forms open cards here; the token goes to the site by itself. */
    public function connectForm(LeadIntake $intake): void
    {
        abort_unless((bool) auth()->user()?->hasRole(Roles::ADMIN), 403);
        $site = (int) $this->formSite;
        if ($site <= 0) {
            throw ValidationException::withMessages(['formSite' => 'Formu gelecek siteyi seç.']);
        }
        try {
            $intake->connectSite($site);
        } catch (Throwable $error) {
            report($error);
            throw ValidationException::withMessages(['formSite' => 'Site bağlantıyı almadı: '.mb_substr($error->getMessage(), 0, 200)]);
        }
        $this->say('Site formu bağlandı. Formdan gelen her kişi buraya kart olarak düşer; gündüz hemen telefona bildirilir.');
    }

    public function render(SalesLeads $leads): View
    {
        $this->updatedTab();
        $due = $leads->due()->get();
        $open = SalesLead::query()->open()->relevant();
        $stageCounts = (clone $open)->get(['stage'])->countBy('stage')->all();
        $list = collect();
        $viewCounts = [];
        $touches = [];
        $offerTotal = 0;
        $customers = ['rows' => [], 'strip' => null];
        if ($this->tab === 'adaylar') {
            [$list, $viewCounts, $offerTotal] = $this->leadList();
            $touches = $this->lastTouches($list);
            if ($this->view === 'musteriler' && trim($this->q) === '') {
                $customers = $this->customerTable(app(Agreements::class));
            }
        }
        $reportMonth = preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $this->month) === 1 ? CarbonImmutable::parse($this->month.'-01', 'Europe/Istanbul') : CarbonImmutable::now('Europe/Istanbul')->startOfMonth();
        $requests = $this->tab === 'istekler' ? $leads->customerRequests() : collect();
        $requestCustomers = $requests->isNotEmpty() ? Customer::query()->whereKey($requests->pluck('customer_id')->unique())->get(['id', 'name', 'monthly_fee'])->keyBy('id') : collect();
        $previews = [];
        if ($requests->isNotEmpty()) {
            $latest = WhatsAppMessage::query()->whereIn('conversation_id', $requests->pluck('id'))->where('direction', 'incoming')->selectRaw('max(id) as id')->groupBy('conversation_id')->pluck('id');
            foreach (WhatsAppMessage::query()->whereKey($latest)->get(['conversation_id', 'body']) as $row) {
                $previews[$row->conversation_id] = mb_substr(trim((string) preg_replace('/\s+/u', ' ', (string) $row->body)), 0, 160);
            }
        }

        return view('livewire.operator.sales.sales-page', [
            'due' => $due,
            'dueCount' => $due->count(),
            'openCount' => (clone $open)->count(),
            'stageCounts' => $stageCounts,
            'list' => $list,
            'viewCounts' => $viewCounts,
            'touches' => $touches,
            'offerTotal' => $offerTotal,
            'dialogCard' => $this->dialog !== '' ? SalesLead::query()->find($this->dialogLead) : null,
            'requests' => $requests,
            'requestCustomers' => $requestCustomers,
            'customerRows' => $customers['rows'],
            'customerStrip' => $customers['strip'],
            'previews' => $previews,
            'results' => $this->tab === 'sonuclar' ? $leads->results() : [],
            'money' => $this->tab === 'sonuclar' ? $this->money() : null,
            'report' => $this->tab === 'sonuclar' ? app(SalesMonthReport::class)->month($reportMonth) : null,
            'patterns' => $this->tab === 'sonuclar' ? app(SalesMonthReport::class)->patterns() : null,
            'reportMonth' => $reportMonth,
            'isThisMonth' => $reportMonth->format('Y-m') === CarbonImmutable::now('Europe/Istanbul')->format('Y-m'),
            'isAdmin' => (bool) auth()->user()?->hasRole(Roles::ADMIN),
            'formSiteId' => $this->tab === 'sonuclar' ? LeadIntake::siteId() : null,
            'formSites' => $this->tab === 'sonuclar' && auth()->user()?->hasRole(Roles::ADMIN) ? $this->formSites() : [],
            'lastForm' => $this->tab === 'sonuclar' ? SalesLead::query()->where('source', 'form')->max('created_at') : null,
            'wonThisMonth' => SalesLead::query()->relevant()->where('stage', 'won')->where('won_at', '>=', now('Europe/Istanbul')->startOfMonth())->count(),
        ]);
    }

    /**
     * Adaylar: the cards of the chosen view (and stage, and search), sorted; the count of every view; the open offers' sum.
     *
     * @return array{0: Collection<int, SalesLead>, 1: array<string, int>, 2: int}
     */
    private function leadList(): array
    {
        if (! isset(self::VIEWS[$this->view])) {
            $this->view = 'acik';
        }
        if (! isset(self::SORTS[$this->sort])) {
            $this->sort = 'oncelik';
        }
        $open = SalesLead::query()->open()->relevant()->get();
        $tests = $this->viewTests();
        $counts = [];
        foreach ($tests as $key => $test) {
            $counts[$key] = $open->filter($test)->count();
        }
        $counts['isimsiz'] = $open->filter(fn (SalesLead $l): bool => blank($l->name))->count();
        $counts['musteriler'] = Brand::query()->operational()->count();
        $counts['kaybedilen'] = SalesLead::query()->relevant()->where('stage', 'lost')->count();
        $offerTotal = (int) $open->filter($tests['teklif'])->sum('offer_amount');

        $term = trim($this->q);
        if ($term !== '') {
            // A search looks at every card, open or closed, and in its note and e-mail too.
            $like = '%'.mb_substr($term, 0, 80).'%';
            $rows = SalesLead::query()->where(fn ($n) => $n->where('name', 'like', $like)->orWhere('company', 'like', $like)->orWhere('city', 'like', $like)
                ->orWhere('phone', 'like', $like)->orWhere('website_host', 'like', $like)->orWhere('email', 'like', $like)->orWhere('notes', 'like', $like)
                ->orWhere('branch', 'like', $like))->orderByDesc('updated_at')->limit(300)->get();
        } elseif ($this->view === 'musteriler') {
            $rows = collect(); // the customer table is built from brands and agreements, not from the cards
        } elseif ($this->view === 'kaybedilen') {
            $rows = SalesLead::query()->relevant()->where('stage', 'lost')->orderByDesc('lost_at')->limit(300)->get();
        } else {
            $rows = $open->filter($tests[$this->view]);
        }
        if ($this->stageFilter !== '' && isset(SalesLead::STAGES[$this->stageFilter])) {
            $rows = $rows->where('stage', $this->stageFilter);
        }

        return [$this->sorted($rows)->values()->take(300), $counts, $offerTotal];
    }

    /** @return array<string, \Closure(SalesLead): bool> how each open view picks its cards */
    private function viewTests(): array
    {
        $cold = now()->subDays(self::COLD_DAYS);

        return [
            'acik' => fn (SalesLead $l): bool => true,
            'borclu' => fn (SalesLead $l): bool => $l->awaitsUs(),
            'bugun' => fn (SalesLead $l): bool => $l->isDue(),
            'adimsiz' => fn (SalesLead $l): bool => $l->next_step_at === null,
            'soguyan' => fn (SalesLead $l): bool => $l->stage !== 'later' && ($l->lastTouchAt() ?? $l->created_at)?->lt($cold) === true,
            'teklif' => fn (SalesLead $l): bool => $l->offer_amount !== null || in_array($l->stage, ['priced', 'thinking'], true),
        ];
    }

    /**
     * @param  Collection<int, SalesLead>  $rows
     * @return Collection<int, SalesLead>
     */
    private function sorted($rows)
    {
        $endOfDay = now()->endOfDay();

        return match ($this->sort) {
            'son_temas' => $rows->sortByDesc(fn (SalesLead $l): int => $l->lastTouchAt()?->getTimestamp() ?? 0),
            'teklif' => $rows->sortByDesc(fn (SalesLead $l): int => (int) $l->offer_amount),
            'bekleyen' => $rows->sortBy(fn (SalesLead $l): int => ($l->stage_at ?? $l->created_at)?->getTimestamp() ?? PHP_INT_MAX),
            // Önce yapılacaklar: the ones waiting for my answer, then due steps (oldest first), then the bigger offers.
            default => $rows->sortBy([
                fn (SalesLead $a, SalesLead $b): int => (int) $b->awaitsUs() <=> (int) $a->awaitsUs(),
                fn (SalesLead $a, SalesLead $b): int => (int) ($b->isOpen() && $b->next_step_at?->lte($endOfDay)) <=> (int) ($a->isOpen() && $a->next_step_at?->lte($endOfDay)),
                fn (SalesLead $a, SalesLead $b): int => ($a->next_step_at?->getTimestamp() ?? PHP_INT_MAX) <=> ($b->next_step_at?->getTimestamp() ?? PHP_INT_MAX),
                fn (SalesLead $a, SalesLead $b): int => (int) $b->offer_amount <=> (int) $a->offer_amount,
            ]),
        };
    }

    /**
     * What was said last with each card, in one line: their or our message (from WhatsApp or the card), a call, a
     * meeting, a note. Two queries for the whole list.
     *
     * @param  Collection<int, SalesLead>  $leads
     * @return array<int, array{at: CarbonImmutable, label: string, text: string}>
     */
    private function lastTouches($leads): array
    {
        if ($leads->isEmpty()) {
            return [];
        }
        $labels = ['message_in' => 'Yazdı', 'message_out' => 'Yazdın', 'call' => 'Arama', 'meeting' => 'Görüşme', 'note' => 'Not', 'offer' => 'Teklif'];
        $found = [];
        $put = function (int $leadId, CarbonImmutable $at, string $label, string $text) use (&$found): void {
            if (! isset($found[$leadId]) || $at->gt($found[$leadId]['at'])) {
                $found[$leadId] = ['at' => $at, 'label' => $label, 'text' => mb_substr(trim((string) preg_replace('/\s+/u', ' ', $text)), 0, 90)];
            }
        };
        $ids = SalesLeadEvent::query()->whereIn('lead_id', $leads->pluck('id'))->whereIn('kind', array_keys($labels))
            ->selectRaw('max(id) as id')->groupBy('lead_id')->pluck('id');
        foreach (SalesLeadEvent::query()->whereKey($ids)->get() as $event) {
            $put((int) $event->lead_id, CarbonImmutable::instance($event->happened_at), $labels[$event->kind], (string) $event->body);
        }
        $byConversation = $leads->whereNotNull('conversation_id')->pluck('id', 'conversation_id');
        if ($byConversation->isNotEmpty()) {
            $latest = WhatsAppMessage::query()->whereIn('conversation_id', $byConversation->keys())
                ->selectRaw('conversation_id, max(sent_at) as at')->groupBy('conversation_id')->pluck('at', 'conversation_id');
            if ($latest->isNotEmpty()) {
                $messages = WhatsAppMessage::query()->whereIn('conversation_id', $latest->keys())->whereIn('sent_at', $latest->values()->unique()->all())
                    ->get(['conversation_id', 'direction', 'body', 'sent_at', 'message_type']);
                foreach ($messages as $message) {
                    if ((string) $latest[$message->conversation_id] !== (string) $message->getRawOriginal('sent_at')) {
                        continue;
                    }
                    $text = filled($message->body) ? (string) $message->body : '('.($message->message_type ?: 'medya').')';
                    $put((int) $byConversation[$message->conversation_id], CarbonImmutable::instance($message->sent_at), $message->direction === 'outgoing' ? 'Yazdın' : 'Yazdı', $text);
                }
            }
        }

        return $found;
    }

    /** @return array{mrr: float, customers: int, priced: int, offer_total: int, won_month: int, lost_month: int} */
    private function money(): array
    {
        $active = Customer::query()->where('status', 'active');
        $offers = SalesLead::query()->open()->relevant()->where(fn ($q) => $q->whereNotNull('offer_amount')->orWhereIn('stage', ['priced', 'thinking']));
        // The agreements are the truth once entered; until then the customers' monthly fee column.
        $mrr = Agreement::query()->exists() ? (float) app(Agreements::class)->mrr() : (float) (clone $active)->sum('monthly_fee');

        return [
            'mrr' => $mrr,
            'customers' => (clone $active)->count(),
            'priced' => (clone $offers)->count(),
            'offer_total' => (int) (clone $offers)->sum('offer_amount'),
            'won_month' => SalesLead::query()->relevant()->where('stage', 'won')->where('won_at', '>=', now('Europe/Istanbul')->startOfMonth())->count(),
            'lost_month' => SalesLead::query()->relevant()->where('stage', 'lost')->where('lost_at', '>=', now('Europe/Istanbul')->startOfMonth())->count(),
        ];
    }

    /** @return array<int, string> connected sites, by id */
    private function formSites(): array
    {
        try {
            return collect(app(WordPressSiteBuilder::class)->sites())
                ->mapWithKeys(fn (array $site): array => [$site['site_id'] => trim($site['name'].' · '.preg_replace('#^https?://#', '', (string) $site['url']), ' ·')])
                ->sort()->all();
        } catch (Throwable $error) {
            report($error);

            return [];
        }
    }

    private function say(string $message): void
    {
        $this->message = $message;
        $this->dispatch('operator-notice', message: $message, tone: 'success');
    }
}
