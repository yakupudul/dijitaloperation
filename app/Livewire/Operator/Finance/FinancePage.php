<?php

namespace App\Livewire\Operator\Finance;

use App\Jobs\Finance\FinanceGmailSyncJob;
use App\Models\Agreement;
use App\Models\AgreementPayment;
use App\Models\Brand;
use App\Models\FinanceDocument;
use App\Models\SalesLead;
use App\Services\Finance\FinanceBoard;
use App\Services\Finance\FinanceDocuments;
use App\Services\Finance\FinanceSettings;
use App\Services\Finance\GmailInvoices;
use App\Services\Sales\Agreements;
use App\Support\Roles;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithFileUploads;
use Throwable;

/**
 * Finans (yakup 2026-11-12): the payments of the work on one screen. Bu ay: who owes what this month, what is late,
 * what is coming, what was spent, which papers are missing. Anlaşmalar: every job (a brand's or one outside the
 * panel) with how it is paid, what came in and what is left; the zam view. Giderler: expense documents and the
 * subscriptions they show. Faturalar: every paper by month, read from Gmail or uploaded, out as one ZIP.
 */
#[Layout('operator.layouts.app')]
#[Title('Finans')]
final class FinancePage extends Component
{
    use WithFileUploads;

    public const array TABS = ['bu-ay' => 'Bu ay', 'anlasmalar' => 'Anlaşmalar', 'giderler' => 'Giderler', 'faturalar' => 'Faturalar'];

    #[Url(as: 'sekme', except: 'bu-ay')]
    public string $tab = 'bu-ay';

    /** The month shown (Y-m); empty = this month. */
    #[Url(as: 'ay', except: '')]
    public string $month = '';

    #[Url(as: 'gorunum', except: 'isler')]
    public string $view = 'isler';

    #[Url(as: 'durum', except: 'active')]
    public string $show = 'active';

    #[Url(as: 'tur', except: '')]
    public string $direction = '';

    /** The payment row opened for a part payment, a reminder or an invoice. */
    public int $openRow = 0;

    public string $rowPanel = '';

    /** @var array{amount: string, on: string, vat: string} */
    public array $pay = ['amount' => '', 'on' => '', 'vat' => 'auto'];

    /** The job form: 0 = closed, -1 = new, else the agreement edited. */
    public int $jobId = 0;

    /** @var array<string, mixed> */
    public array $job = [];

    /** The job whose rows are shown. */
    public int $openJob = 0;

    /** The document edited. */
    public int $docId = 0;

    /** @var array<string, mixed> */
    public array $doc = [];

    public bool $addingExpense = false;

    /** @var array<int, mixed> */
    public array $files = [];

    /** Where the files being uploaded go: '' = let the system say, out, in; and the row they were dropped on. */
    public string $uploadAs = '';

    public int $uploadRow = 0;

    /** @var array{own_tax_no: string, inflation: string, vat: string} */
    public array $settings = ['own_tax_no' => '', 'inflation' => '', 'vat' => '20'];

    public string $message = '';

    public function mount(FinanceSettings $settings): void
    {
        abort_unless((bool) auth()->user()?->hasRole(Roles::ADMIN), 403);
        $this->check();
        $all = $settings->all();
        $this->settings = ['own_tax_no' => $all['own_tax_no'], 'inflation' => $all['inflation'] > 0 ? rtrim(rtrim(number_format($all['inflation'], 1, ',', ''), '0'), ',') : '', 'vat' => (string) $all['vat']];
    }

    public function updated(string $name): void
    {
        $this->check();
        if ($name === 'files') {
            $this->upload();
        }
    }

    public function shift(int $by): void
    {
        $this->month = $this->monthDate()->addMonths($by)->format('Y-m');
        if ($this->month === CarbonImmutable::now(Agreements::TZ)->format('Y-m')) {
            $this->month = '';
        }
        $this->closeRow();
    }

    // ── Payments ──

    public function paid(int $paymentId, Agreements $agreements): void
    {
        $payment = AgreementPayment::query()->with('agreement.brand')->findOrFail($paymentId);
        $agreements->markPaid($payment);
        $this->closeRow();
        $this->say($payment->agreement->payer().' · '.$payment->title().': ödendi.');
    }

    public function unpaid(int $paymentId, Agreements $agreements): void
    {
        $payment = AgreementPayment::query()->with('agreement.brand')->findOrFail($paymentId);
        $agreements->unpay($payment);
        $this->say($payment->agreement->payer().' · '.$payment->title().': geri alındı, açık.');
    }

    public function openPanel(int $paymentId, string $panel): void
    {
        if ($this->openRow === $paymentId && $this->rowPanel === $panel) {
            $this->closeRow();

            return;
        }
        $this->openRow = $paymentId;
        $this->rowPanel = in_array($panel, ['pay', 'remind', 'invoice'], true) ? $panel : '';
        $this->pay = ['amount' => '', 'on' => CarbonImmutable::now(Agreements::TZ)->toDateString(), 'vat' => 'auto'];
        $this->uploadRow = $panel === 'invoice' ? $paymentId : 0;
        $this->uploadAs = $panel === 'invoice' ? FinanceDocument::OUT : '';
    }

    public function closeRow(): void
    {
        $this->openRow = 0;
        $this->rowPanel = '';
        $this->uploadRow = 0;
        $this->uploadAs = '';
    }

    /** Money came in for the opened row's job, whatever the amount: it fills the open rows from the oldest. */
    public function receive(Agreements $agreements, FinanceBoard $board): void
    {
        $payment = AgreementPayment::query()->with('agreement.brand')->findOrFail($this->openRow);
        $amount = (float) str_replace(',', '.', str_replace('.', '', preg_replace('/[^\d,.]/', '', $this->pay['amount'])));
        if ($amount <= 0) {
            throw ValidationException::withMessages(['pay.amount' => 'Gelen tutarı yaz.']);
        }
        $net = $board->net($payment->agreement, $amount, ['yes' => true, 'no' => false][$this->pay['vat']] ?? null);
        $result = $agreements->receive($payment->agreement, $net['net'], $this->pay['on'] ?: null);
        $agreement = $payment->agreement->fresh('payments');
        $left = $agreement->remaining();
        $this->closeRow();
        $this->say($agreement->payer().': '.$this->tl($net['net']).($net['with_vat'] ? ' (KDV ayrıldı)' : '').' işlendi.'
            .($left !== null ? ' Kalan '.$this->tl($left).'.' : '').($result['left'] > 0 ? ' '.$this->tl($result['left']).' yerleşecek açık satır bulamadı.' : ''));
    }

    /** A dekont that came over WhatsApp: the payment it points at is filled with its amount. */
    public function applyReceipt(int $docId, Agreements $agreements, FinanceBoard $board): void
    {
        $doc = FinanceDocument::query()->where('direction', FinanceDocument::RECEIPT)->with('agreement.brand')->findOrFail($docId);
        if ($doc->agreement === null || $doc->amount === null) {
            $this->editDoc($docId);

            return;
        }
        $net = $board->net($doc->agreement, (float) $doc->amount);
        $agreements->receive($doc->agreement, $net['net'], $doc->doc_date?->toDateString(), 'Dekont');
        $doc->forceFill(['status' => 'filed'])->save();
        $left = $doc->agreement->fresh('payments')->remaining();
        $this->say($doc->agreement->payer().': '.$this->tl($net['net']).' dekonttan işlendi.'.($left !== null ? ' Kalan '.$this->tl($left).'.' : ''));
    }

    // ── Jobs ──

    public function newJob(?int $brandId = null): void
    {
        $this->jobId = -1;
        $this->job = ['brand_id' => $brandId ? (string) $brandId : '', 'party' => '', 'party_phone' => '', 'title' => '', 'kind' => 'one_time', 'fee' => '', 'term_months' => '6',
            'starts_on' => CarbonImmutable::now(Agreements::TZ)->toDateString(), 'due_day' => (string) min(28, CarbonImmutable::now(Agreements::TZ)->day), 'plan' => 'auto',
            'installments' => [['due_on' => CarbonImmutable::now(Agreements::TZ)->toDateString(), 'amount' => '']], 'services' => [], 'tax_no' => '', 'note' => '', 'fee_since' => ''];
        $this->tab = 'anlasmalar';
    }

    public function editJob(int $id): void
    {
        $a = Agreement::query()->with('payments')->findOrFail($id);
        $this->jobId = $a->id;
        $this->job = ['brand_id' => (string) ($a->brand_id ?? ''), 'party' => (string) $a->party, 'party_phone' => (string) $a->party_phone, 'title' => (string) $a->title, 'kind' => $a->kind,
            'fee' => (string) $a->fee, 'term_months' => (string) ($a->term_months ?? '6'), 'starts_on' => (string) $a->starts_on?->toDateString(), 'due_day' => (string) $a->due_day, 'plan' => $a->plan ?: 'auto',
            'installments' => $a->payments->filter(fn (AgreementPayment $p): bool => $p->paid_on === null && (int) $p->paid_amount === 0)
                ->map(fn (AgreementPayment $p): array => ['due_on' => $p->due_on->toDateString(), 'amount' => (string) $p->amount, 'label' => (string) $p->label])->values()->all(),
            'services' => (array) $a->services, 'tax_no' => (string) $a->tax_no, 'note' => (string) $a->note, 'fee_since' => ''];
    }

    public function addInstallment(): void
    {
        $last = collect($this->job['installments'] ?? [])->last();
        $next = filled($last['due_on'] ?? null) ? rescue(fn (): string => CarbonImmutable::parse($last['due_on'])->addMonth()->toDateString(), '', false) : '';
        $this->job['installments'][] = ['due_on' => $next, 'amount' => (string) ($last['amount'] ?? '')];
    }

    public function removeInstallment(int $i): void
    {
        $rows = (array) ($this->job['installments'] ?? []);
        unset($rows[$i]);
        $this->job['installments'] = array_values($rows);
    }

    public function saveJob(Agreements $agreements): void
    {
        if ($this->jobId === 0) {
            return;
        }
        $data = $this->job + ['kind' => 'one_time'];
        $brand = filled($data['brand_id'] ?? null) ? Brand::query()->find((int) $data['brand_id']) : null;
        try {
            if ($this->jobId > 0) {
                $agreement = $agreements->update(Agreement::query()->findOrFail($this->jobId), $data);
            } else {
                $agreement = $agreements->start($brand, $data + ['beside' => in_array($data['kind'], Agreements::BESIDE_KINDS, true)], auth()->user());
            }
        } catch (ValidationException $error) {
            $this->setErrorBag(collect($error->errors())->mapWithKeys(fn (array $m, string $k): array => ['job.'.$k => $m])->all());

            return;
        }
        $this->jobId = 0;
        $this->openJob = $agreement->id;
        $this->say($agreement->name().' kaydedildi: '.$agreement->feeLabel().'.');
    }

    public function setJobStatus(int $id, string $status, Agreements $agreements): void
    {
        $agreement = Agreement::query()->findOrFail($id);
        $agreements->setStatus($agreement, $status);
        $this->say($agreement->name().': '.mb_strtolower($agreement->fresh()->statusLabel()).'.');
    }

    public function toggleJob(int $id): void
    {
        $this->openJob = $this->openJob === $id ? 0 : $id;
    }

    // ── Documents ──

    /** The chosen files are kept, read and filed; what could not be filed waits under "Bakılacak belgeler". */
    public function upload(): void
    {
        $documents = app(FinanceDocuments::class);
        $row = $this->uploadRow > 0 ? AgreementPayment::query()->find($this->uploadRow) : null;
        $kept = 0;
        $errors = [];
        foreach ($this->files as $file) {
            try {
                $hint = array_filter(['direction' => $this->uploadAs ?: null, 'user_id' => auth()->id(), 'agreement_id' => $row?->agreement_id, 'payment_id' => $row?->id]);
                $docs = $documents->keep((string) file_get_contents($file->getRealPath()), $file->getClientOriginalName(), (string) $file->getMimeType(), $hint);
                foreach ($docs as $doc) {
                    if ($row !== null) {
                        $doc->forceFill(['agreement_id' => $row->agreement_id, 'payment_id' => $row->id, 'brand_id' => $row->agreement?->brand_id, 'direction' => FinanceDocument::OUT, 'status' => 'filed'])->save();
                    }
                    $kept++;
                }
            } catch (ValidationException $error) {
                $errors[] = $file->getClientOriginalName().': '.collect($error->errors())->flatten()->first();
            } catch (Throwable $error) {
                report($error);
                $errors[] = $file->getClientOriginalName().': okunamadı.';
            }
        }
        $this->files = [];
        if ($row !== null) {
            $this->closeRow();
        }
        $this->say(trim(($kept > 0 ? $kept.' belge alındı.' : '').' '.implode(' ', $errors)), $errors === [] ? 'success' : 'error');
    }

    public function editDoc(int $id): void
    {
        $d = FinanceDocument::query()->findOrFail($id);
        $this->docId = $d->id;
        $this->addingExpense = false;
        $this->doc = ['direction' => $d->direction, 'counterparty' => (string) $d->counterparty, 'counterparty_tax_no' => (string) $d->counterparty_tax_no, 'doc_no' => (string) $d->doc_no,
            'doc_date' => (string) $d->doc_date?->toDateString(), 'amount' => $d->amount !== null ? number_format((float) $d->amount, 2, ',', '') : '', 'currency' => $d->currency,
            'category' => (string) ($d->category ?? 'diger'), 'agreement_id' => (string) ($d->agreement_id ?? ''), 'brand_id' => (string) ($d->brand_id ?? ''), 'rebill' => (bool) $d->rebill, 'note' => (string) $d->note];
    }

    public function newExpense(): void
    {
        $this->docId = 0;
        $this->addingExpense = true;
        $this->doc = ['direction' => FinanceDocument::IN, 'counterparty' => '', 'doc_no' => '', 'doc_date' => CarbonImmutable::now(Agreements::TZ)->toDateString(), 'amount' => '', 'currency' => 'TRY',
            'category' => 'yazilim', 'agreement_id' => '', 'brand_id' => '', 'rebill' => false, 'note' => '', 'counterparty_tax_no' => ''];
        $this->tab = 'giderler';
    }

    public function saveDoc(FinanceDocuments $documents): void
    {
        try {
            if ($this->addingExpense) {
                $documents->manual($this->doc, auth()->id());
            } else {
                $documents->update(FinanceDocument::query()->findOrFail($this->docId), $this->doc);
            }
        } catch (ValidationException $error) {
            $this->setErrorBag(collect($error->errors())->mapWithKeys(fn (array $m, string $k): array => ['doc.'.$k => $m])->all());

            return;
        }
        $this->docId = 0;
        $this->addingExpense = false;
        $this->say('Belge kaydedildi.');
    }

    public function closeDoc(): void
    {
        $this->docId = 0;
        $this->addingExpense = false;
        $this->jobId = 0;
    }

    /** "Yerinde": what the system read and where it filed the document is right. */
    public function fileDoc(int $id, FinanceDocuments $documents): void
    {
        $documents->update(FinanceDocument::query()->findOrFail($id), []);
        $this->say('Belge yerinde.');
    }

    public function ignoreDoc(int $id): void
    {
        FinanceDocument::query()->whereKey($id)->update(['status' => 'ignored']);
        $this->say('Belge listeden çıkarıldı (dosya durur; Faturalar › Yok sayılanlar\'dan geri alınır).');
    }

    public function restoreDoc(int $id): void
    {
        FinanceDocument::query()->whereKey($id)->where('status', 'ignored')->update(['status' => 'new']);
    }

    public function deleteDoc(int $id, FinanceDocuments $documents): void
    {
        $doc = FinanceDocument::query()->findOrFail($id);
        // Only what the operator put in by hand is deleted here; what came from Gmail or WhatsApp is set aside instead.
        abort_unless(in_array($doc->source, ['manual', 'upload'], true), 422);
        $documents->delete($doc);
        $this->docId = 0;
        $this->say('Belge silindi.');
    }

    /** An expense paid for a customer becomes a one-time job of that customer, to be asked back. */
    public function rebill(int $id, Agreements $agreements): void
    {
        $doc = FinanceDocument::query()->with('brand')->where('direction', FinanceDocument::IN)->findOrFail($id);
        if ($doc->brand === null || $doc->amount === null || $doc->currency !== 'TRY') {
            $this->say('Önce belgeyi aç: müşteriyi seç, tutarı ₺ olarak yaz.', 'error');

            return;
        }
        $agreement = $agreements->start($doc->brand, ['kind' => 'one_time', 'fee' => (int) round((float) $doc->amount / app(FinanceSettings::class)->vatFactor()),
            'starts_on' => CarbonImmutable::now(Agreements::TZ)->toDateString(), 'title' => mb_substr((string) $doc->counterparty.' yansıtma', 0, 160), 'beside' => true,
            'note' => 'Müşteri adına ödenen gider: '.$doc->counterparty.' '.$doc->amountLabel().' ('.$doc->doc_date?->format('d.m.Y').')'], auth()->user());
        $doc->forceFill(['rebilled_agreement_id' => $agreement->id])->save();
        $this->say($doc->brand->name.' için '.$agreement->feeLabel().' + KDV alacak açıldı.');
    }

    // ── Gmail and settings ──

    public function syncGmail(GmailInvoices $gmail): void
    {
        if (! $gmail->linked()) {
            $this->say('Önce Gmail\'i bağla.', 'error');

            return;
        }
        FinanceGmailSyncJob::dispatch();
        $this->say('Gmail okunuyor; yeni faturalar birkaç dakika içinde burada görünür.');
    }

    public function rereadGmail(GmailInvoices $gmail): void
    {
        $gmail->restart();
        $this->syncGmail($gmail);
    }

    public function saveSettings(FinanceSettings $settings): void
    {
        $tax = (string) preg_replace('/\D+/', '', $this->settings['own_tax_no']);
        if ($tax !== '' && ! in_array(strlen($tax), [10, 11], true)) {
            $this->addError('settings.own_tax_no', 'Vergi no 10, TC kimlik no 11 hanedir.');

            return;
        }
        $settings->put(['own_tax_no' => $tax, 'inflation' => (float) str_replace(',', '.', $this->settings['inflation']), 'vat' => (int) $this->settings['vat']]);
        $this->say('Ayarlar kaydedildi.');
    }

    public function render(FinanceBoard $board, GmailInvoices $gmail): View
    {
        $month = $this->monthDate();
        $data = ['tabs' => self::TABS, 'monthDate' => $month, 'isThisMonth' => $this->month === '', 'tlf' => fn (int|float $v): string => $this->tl($v)];
        $data['fresh'] = FinanceDocument::query()->where('status', 'new')->where('direction', '!=', FinanceDocument::RECEIPT)->count();
        if ($this->tab === 'bu-ay') {
            $data['board'] = $board->month($month);
            $data['openPayment'] = $this->openRow > 0 ? AgreementPayment::query()->with('agreement.brand')->find($this->openRow) : null;
            $data['reminder'] = $data['openPayment'] !== null && $this->rowPanel === 'remind'
                ? ['text' => $board->reminderText($data['openPayment']), 'phone' => $board->phoneOf($data['openPayment']->agreement)] : null;
        } elseif ($this->tab === 'anlasmalar') {
            $data['jobs'] = $this->view === 'zam' ? collect() : $board->jobs($this->show);
            $data['raises'] = $this->view === 'zam' ? $board->raises() : null;
            $data['brands'] = $this->jobId !== 0 ? Brand::query()->orderBy('name')->get(['id', 'name']) : collect();
            $data['services'] = SalesLead::SERVICES;
        } else {
            $from = $month->startOfMonth()->toDateString();
            $to = $month->endOfMonth()->toDateString();
            $docs = FinanceDocument::query()->with('agreement.brand:id,name', 'brand:id,name')->whereDate('doc_date', '>=', $from)->whereDate('doc_date', '<=', $to);
            if ($this->tab === 'giderler') {
                $data['docs'] = $docs->where('direction', FinanceDocument::IN)->where('status', '!=', 'ignored')->orderByDesc('doc_date')->orderByDesc('id')->get();
                $data['subscriptions'] = $board->subscriptions();
                $data['spend'] = $board->month($month)['spend'];
            } else {
                $data['ignored'] = $this->direction === 'yok';
                $data['docs'] = $docs->when($this->direction === 'yok', fn ($q) => $q->where('status', 'ignored'), fn ($q) => $q->where('status', '!=', 'ignored'))
                    ->when(isset(FinanceDocument::DIRECTIONS[$this->direction]), fn ($q) => $q->where('direction', $this->direction))->orderByDesc('doc_date')->orderByDesc('id')->get();
                $data['gmail'] = $gmail->state() + ['connect' => $gmail->connectUrl()];
            }
            $data['waiting'] = FinanceDocument::query()->with('agreement.brand:id,name', 'brand:id,name')->where('status', 'new')->where('direction', '!=', FinanceDocument::RECEIPT)
                ->when($this->tab === 'giderler', fn ($q) => $q->where('direction', FinanceDocument::IN))->orderByDesc('id')->limit(40)->get();
        }
        if ($this->docId > 0 || $this->addingExpense) {
            $data['agreementOptions'] = Agreement::query()->with('brand:id,name')->orderByRaw("case when status = 'active' then 0 else 1 end")->orderByDesc('id')->limit(300)->get();
            $data['brandOptions'] = Brand::query()->orderBy('name')->get(['id', 'name']);
            $data['editing'] = $this->docId > 0 ? FinanceDocument::query()->find($this->docId) : null;
        }

        return view('livewire.operator.finance.finance-page', $data);
    }

    private function monthDate(): CarbonImmutable
    {
        return $this->month !== '' ? CarbonImmutable::parse($this->month.'-01', Agreements::TZ) : CarbonImmutable::now(Agreements::TZ)->startOfMonth();
    }

    private function check(): void
    {
        if (! isset(self::TABS[$this->tab])) {
            $this->tab = 'bu-ay';
        }
        if ($this->month !== '' && preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $this->month) !== 1) {
            $this->month = '';
        }
        $this->view = $this->view === 'zam' ? 'zam' : 'isler';
        $this->show = $this->show === 'ended' ? 'ended' : 'active';
    }

    private function tl(int|float $v): string
    {
        return number_format((float) $v, 0, ',', '.').' ₺';
    }

    private function say(string $message, string $tone = 'success'): void
    {
        $this->message = $message;
        $this->dispatch('operator-notice', message: $message, tone: $tone);
    }
}
