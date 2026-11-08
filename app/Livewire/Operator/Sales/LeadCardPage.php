<?php

namespace App\Livewire\Operator\Sales;

use App\Models\AgreementPayment;
use App\Models\Brand;
use App\Models\Reminder;
use App\Models\SalesLead;
use App\Models\ServiceCategory;
use App\Services\Sales\Agreements;
use App\Services\Sales\LeadResearcher;
use App\Services\Sales\ProspectSiteCheck;
use App\Services\Sales\SalesAnalysisWriter;
use App\Services\Sales\SalesLeads;
use App\Services\Sales\SalesReplyWriter;
use App\Services\Sales\SalesShare;
use App\Support\Roles;
use App\Support\Sales\LeadKinds;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Müşteri kaydı (kişi kartı): who they are (and what kind of business: Tür + Sektör), where they came from, the stage,
 * the next step, the ready text with "WhatsApp'ta aç", the findings, the opportunities (the sale being pursued, the
 * ones won and lost), the brand's agreement once they are a customer, and the timeline of everything. Everything the
 * operator may need about this record is reachable from here (yakup 2026-11-07).
 */
#[Layout('operator.layouts.app')]
#[Title('Aday')]
final class LeadCardPage extends Component
{
    public int $leadId;

    public bool $editing = false;

    /** @var array<string, string> */
    public array $form = [];

    public string $draft = '';

    public string $note = '';

    public string $nextStep = '';

    public string $nextStepAt = '';

    public string $lostReason = '';

    public string $message = '';

    /** "Sohbeti yapıştır": text copied from WhatsApp, and an optional word to Claude ("telefonda fiyat sordu"…). */
    public string $paste = '';

    public string $pasteNote = '';

    /** "Kazanıldı: müşteri yap" opens the terms (the brand's first agreement) instead of a bare confirm. */
    public bool $winning = false;

    /** The opportunity the win form closes (0 = the record's open one / none). */
    public int $winningOpportunity = 0;

    /** @var array{kind: string, fee: string, term_months: string, starts_on: string, due_day: string, services: array<int, string>, note: string} */
    public array $agreement = ['kind' => 'monthly', 'fee' => '', 'term_months' => '6', 'starts_on' => '', 'due_day' => '1', 'services' => [], 'note' => ''];

    /** "+ Yeni fırsat" form. */
    public bool $opening = false;

    /** @var array{service: string, title: string, amount: string, billing: string, note: string} */
    public array $opp = ['service' => '', 'title' => '', 'amount' => '', 'billing' => 'monthly', 'note' => ''];

    public string $oppLostReason = '';

    public function mount(string $lead): void
    {
        $row = SalesLead::query()->findOrFail((int) $lead);
        $this->leadId = $row->id;
        $this->draft = (string) $row->draft;
        $this->nextStep = (string) $row->next_step;
        $this->nextStepAt = $row->next_step_at?->timezone('Europe/Istanbul')->toDateString() ?? '';
        $this->message = (string) session('sales_notice', '');
        $this->form = $this->formFrom($row);
    }

    private function formFrom(SalesLead $lead): array
    {
        $out = [];
        foreach (['name', 'company', 'city', 'branch', 'phone', 'email', 'website', 'source', 'heard_from', 'notes', 'kind', 'sector_id'] as $key) {
            $out[$key] = (string) ($lead->{$key} ?? '');
        }

        return $out;
    }

    public function edit(): void
    {
        $this->form = $this->formFrom($this->lead());
        $this->editing = true;
    }

    public function save(SalesLeads $leads): void
    {
        $lead = $this->lead();
        $hadSite = $lead->website_host;
        foreach (SalesLeads::LIMITS as $key => $limit) {
            $lead->{$key} = trim((string) ($this->form[$key] ?? '')) !== '' ? mb_substr(trim((string) $this->form[$key]), 0, $limit) : null;
        }
        if (isset(SalesLead::SOURCES[$this->form['source'] ?? ''])) {
            $lead->source = $this->form['source'];
        }
        $email = trim((string) ($this->form['email'] ?? ''));
        if ($email !== '' && SalesLeads::email($email) === null) {
            throw ValidationException::withMessages(['form.email' => 'E-posta geçerli görünmüyor.']);
        }
        $lead->email = SalesLeads::email($email);
        $lead->heard_from = isset(SalesLead::HEARD_FROM[$this->form['heard_from'] ?? '']) ? $this->form['heard_from'] : null;
        $kind = (string) ($this->form['kind'] ?? '');
        if ($kind !== $lead->kind) {
            $lead->kind = LeadKinds::has($kind) ? $kind : null;
            $lead->classification = array_merge((array) ($lead->classification ?? []), ['confirmed' => $lead->kind !== null]);
        }
        $sector = (int) ($this->form['sector_id'] ?? 0);
        $lead->sector_id = $sector > 0 && ServiceCategory::query()->whereKey($sector)->exists() ? $sector : null;
        $lead->phone_key = SalesLeads::phoneKey($lead->phone);
        $lead->website_host = SalesLeads::host($lead->website);
        if ($lead->website_host !== null && ! preg_match('#^https?://#i', (string) $lead->website)) {
            $lead->website = 'https://'.ltrim((string) $lead->website, '/');
        }
        $lead->save();
        $this->editing = false;
        if ($lead->kind === null || $lead->sector_id === null) {
            $leads->classify($lead);
        }
        if ($lead->website_host !== null && $lead->website_host !== $hadSite) {
            ProspectSiteCheck::queue($lead);
        }
        $linked = $leads->linkConversation($lead);
        $this->say($linked ? 'Kart kaydedildi; bu numaranın WhatsApp görüşmesi karta bağlandı.' : 'Kart kaydedildi.');
    }

    public function saveDraft(): void
    {
        $this->lead()->forceFill(['draft' => mb_substr(trim($this->draft), 0, 4000) ?: null])->save();
        $this->say('Metin kaydedildi.');
    }

    /** "Yeniden yaz" / "Yaz": Claude writes the next message now; the page waits (10-30 s). */
    public function writeNow(SalesReplyWriter $writer): void
    {
        $this->resetErrorBag('draft');
        if ((int) ini_get('max_execution_time') > 0) {
            @set_time_limit(150); // web only: the CLI (queue, tests) has no limit and must keep none
        }
        $this->afterWrite($writer->write($this->leadId, 50));
    }

    /** "Sohbeti yapıştır": the chat lands on this card, then Claude answers at once. */
    public function pasteChat(SalesShare $share, SalesLeads $leads, SalesReplyWriter $writer): void
    {
        $this->resetErrorBag('paste');
        $text = trim($this->paste);
        $note = trim($this->pasteNote);
        if ($text === '' && $note === '') {
            $this->addError('paste', 'Sohbeti ya da Claude\'a notunu yaz.');

            return;
        }
        $lead = $this->lead();
        if ($text !== '') {
            $result = $share->importChat(mb_substr($text, 0, 400_000), '', auth()->user(), $lead);
            if (! str_contains($result['notice'], 'mesaj karta işlendi')) {
                $this->addError('paste', $result['notice']);

                return;
            }
        }
        if ($note !== '') {
            $leads->event($lead->fresh(), 'note', mb_substr($note, 0, 4000), meta: ['by' => auth()->id(), 'for_ai' => true]);
        }
        $this->paste = '';
        $this->pasteNote = '';
        if ((int) ini_get('max_execution_time') > 0) {
            @set_time_limit(150); // web only: the CLI (queue, tests) has no limit and must keep none
        }
        $this->afterWrite($writer->write($lead->id, 50));
    }

    private function afterWrite(bool $ok): void
    {
        $fresh = $this->lead();
        $this->draft = (string) $fresh->draft;
        $this->nextStep = (string) $fresh->next_step;
        $this->nextStepAt = $fresh->next_step_at?->timezone('Europe/Istanbul')->toDateString() ?? '';
        if (! $ok && $fresh->draft_status === 'waiting') {
            $this->say((string) $fresh->draft_note);

            return;
        }
        if (! $ok) {
            $this->addError('draft', (string) ($fresh->draft_note ?: 'Yazılamadı.'));

            return;
        }
        $this->say($fresh->draft_send_on !== null && $fresh->draft_send_on->toDateString() > now('Europe/Istanbul')->toDateString()
            ? 'Claude yazdı; bugün değil, '.$fresh->draft_send_on->locale('tr')->translatedFormat('j F l').' gönderilmeli.'
            : 'Claude yazdı; metin hazır.');
    }

    public function markSent(SalesLeads $leads): void
    {
        $lead = $this->lead();
        $text = trim($this->draft);
        $leads->markSent($lead, $text !== '' ? $text : null, auth()->user());
        $fresh = $lead->fresh();
        $this->draft = '';
        $this->nextStep = (string) $fresh->next_step;
        $this->nextStepAt = $fresh->next_step_at?->timezone('Europe/Istanbul')->toDateString() ?? '';
        $this->say('Gönderildi olarak işlendi. Sonraki adım: '.$fresh->next_step.' · '.$fresh->next_step_at?->timezone('Europe/Istanbul')->format('d.m.Y'));
    }

    public function addNote(SalesLeads $leads): void
    {
        $note = trim($this->note);
        if ($note === '') {
            return;
        }
        $kind = preg_match('/^(aradım|arama|görüştüm|telefon)/iu', $note) ? 'call' : 'note';
        $leads->event($this->lead(), $kind, mb_substr($note, 0, 4000), meta: ['by' => auth()->id()]);
        $this->note = '';
        $this->say('Not eklendi.');
    }

    public function saveNextStep(SalesLeads $leads): void
    {
        if ($this->nextStepAt === '') {
            $this->addError('nextStepAt', 'Bir tarih seç.');

            return;
        }
        try {
            $leads->setNextStep($this->lead(), $this->nextStep, $this->nextStepAt, auth()->user());
        } catch (\Throwable) {
            $this->addError('nextStepAt', 'Tarih okunamadı.');

            return;
        }
        $this->say('Sonraki adım kaydedildi.');
    }

    public string $laterDate = '';

    /** "Şimdi değil": the card rests until the chosen day, then comes back on Bugün and Takvim. */
    public function later(SalesLeads $leads): void
    {
        $this->resetErrorBag('laterDate');
        $today = CarbonImmutable::now('Europe/Istanbul')->toDateString();
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $this->laterDate) !== 1 || $this->laterDate <= $today) {
            throw ValidationException::withMessages(['laterDate' => 'Yarından sonraki bir gün seç.']);
        }
        $leads->later($this->lead(), $this->laterDate, auth()->user());
        $fresh = $this->lead()->fresh();
        $this->nextStep = (string) $fresh->next_step;
        $this->nextStepAt = $fresh->next_step_at?->timezone('Europe/Istanbul')->toDateString() ?? '';
        $this->say('Kart '.CarbonImmutable::parse($this->laterDate)->format('d.m.Y').' tarihine ertelendi; o gün Takvim\'de ve Bugün\'de.');
        $this->laterDate = '';
    }

    public function setStage(string $stage, SalesLeads $leads): void
    {
        $lead = $this->lead();
        if ($stage === 'won') {
            $this->win($leads);

            return;
        }
        if ($stage === 'lost' && ! isset(SalesLead::LOST_REASONS[$this->lostReason])) {
            // A card closes only with its reason (the month report's loss reasons depend on it).
            $this->addError('lostReason', 'Neden kapandığını seç.');

            return;
        }
        $leads->setStage($lead, $stage, $stage === 'lost' ? $this->lostReason : null, auth()->user());
        $fresh = $lead->fresh();
        $this->nextStep = (string) $fresh->next_step;
        $this->nextStepAt = $fresh->next_step_at?->timezone('Europe/Istanbul')->toDateString() ?? '';
        $this->say('Aşama: '.$fresh->stageLabel().'.');
    }

    /** "Kazanıldı: müşteri yap" / "Fırsatı kazan": the terms form opens, filled from the offer or the opportunity. */
    public function openWin(int $opportunityId = 0): void
    {
        abort_unless(auth()->user()?->hasRole(Roles::ADMIN), 403);
        $lead = $this->lead();
        $opportunity = $opportunityId > 0 ? $lead->opportunities()->whereKey($opportunityId)->first() : $lead->openOpportunity();
        $amount = $opportunity?->amount ?? $lead->offer_amount;
        $service = $opportunity?->service ?? $lead->offer_service;
        $billing = $opportunity?->billing ?? ($service === 'web' ? 'one_time' : 'monthly');
        $this->agreement = [
            'kind' => $billing, 'fee' => $amount !== null ? (string) $amount : '', 'term_months' => '6',
            'starts_on' => CarbonImmutable::now('Europe/Istanbul')->toDateString(), 'due_day' => '1',
            'services' => $service !== null ? [$service] : [], 'note' => '',
        ];
        $this->winningOpportunity = (int) ($opportunity?->id ?? 0);
        $this->winning = true;
        $this->resetErrorBag();
    }

    public function cancelWin(): void
    {
        $this->winning = false;
        $this->winningOpportunity = 0;
    }

    /** Wins the record (customer + brand once) and starts the brand's agreement with the terms typed; no fee = no agreement yet. */
    public function win(SalesLeads $leads): void
    {
        abort_unless(auth()->user()?->hasRole(Roles::ADMIN), 403);
        $lead = $this->lead();
        $terms = trim((string) $this->agreement['fee']) !== '' ? $this->agreement : null;
        try {
            $opportunity = $this->winningOpportunity > 0 ? $lead->opportunities()->whereKey($this->winningOpportunity)->first() : null;
            if ($opportunity !== null && $opportunity->isOpen() && $lead->isCustomer() && $lead->brand_id !== null) {
                $leads->closeOpportunity($opportunity, 'won', null, auth()->user(), $terms);
                $brand = Brand::query()->find($lead->brand_id);
            } else {
                $brand = $leads->win($lead, auth()->user(), $terms);
            }
        } catch (ValidationException $exception) {
            $this->setErrorBag($exception->validator->errors());
            $this->say((string) collect($exception->errors())->flatten()->first());

            return;
        }
        $this->winning = false;
        $this->winningOpportunity = 0;
        $this->say('Kazanıldı: '.($brand?->name ?? $lead->title()).($terms !== null ? ' · anlaşma başladı.' : ' markası açıldı; anlaşmayı marka sayfasından gir.'));
    }

    /** "Doğru": Claude's suggested kind becomes the record's. */
    public function confirmKind(SalesLeads $leads): void
    {
        $lead = $this->lead();
        $kind = $lead->suggestedKind() ?? $lead->kind;
        if ($kind === null || ! LeadKinds::has($kind)) {
            return;
        }
        $leads->fill($lead, ['kind' => $kind]);
        $lead->save();
        $this->say('Tür: '.LeadKinds::label($kind).'.');
    }

    /** "+ Yeni fırsat": a sale to pursue on this record. */
    public function newOpportunity(SalesLeads $leads): void
    {
        $this->resetErrorBag();
        try {
            $opportunity = $leads->openOpportunity($this->lead(), $this->opp, auth()->user());
        } catch (ValidationException $exception) {
            $this->setErrorBag($exception->validator->errors());

            return;
        }
        $this->opening = false;
        $this->opp = ['service' => '', 'title' => '', 'amount' => '', 'billing' => 'monthly', 'note' => ''];
        $fresh = $this->lead();
        $this->nextStep = (string) $fresh->next_step;
        $this->nextStepAt = $fresh->next_step_at?->timezone('Europe/Istanbul')->toDateString() ?? '';
        $this->say('Fırsat açıldı: '.$opportunity->title().' · '.$opportunity->amountLabel());
    }

    public function loseOpportunity(int $id, SalesLeads $leads): void
    {
        $opportunity = $this->lead()->opportunities()->whereKey($id)->firstOrFail();
        if (! isset(SalesLead::LOST_REASONS[$this->oppLostReason])) {
            $this->addError('oppLostReason', 'Neden kaybedildiğini seç.');

            return;
        }
        $leads->closeOpportunity($opportunity, 'lost', $this->oppLostReason, auth()->user());
        $this->oppLostReason = '';
        $fresh = $this->lead();
        $this->nextStep = (string) $fresh->next_step;
        $this->nextStepAt = $fresh->next_step_at?->timezone('Europe/Istanbul')->toDateString() ?? '';
        $this->say('Fırsat kapandı: '.$opportunity->title().'.');
    }

    /** The month's tick on the brand's agreement (the same tick as on the brand page). */
    public function togglePaid(int $paymentId, Agreements $agreements): void
    {
        $lead = $this->lead();
        $payment = AgreementPayment::query()->whereKey($paymentId)->whereHas('agreement', fn ($q) => $q->where('brand_id', $lead->brand_id))->firstOrFail();
        $payment->isPaid() ? $agreements->unpay($payment) : $agreements->markPaid($payment);
        $this->say($payment->periodLabel().': '.($payment->fresh()->isPaid() ? 'ödendi' : 'ödenmedi').'.');
    }

    /** "İlgisiz": never a prospect; leaves every list and count. */
    public function markIrrelevant(SalesLeads $leads): void
    {
        $leads->markIrrelevant($this->lead(), auth()->user());
        $this->draft = '';
        $this->say('Kayıt ilgisiz olarak işaretlendi; listelerde ve raporlarda sayılmaz.');
    }

    /** "Aynı kişi" banner: this card keeps everything, the other one's story and tasks move here and it goes. */
    public function mergeDuplicate(SalesLeads $leads): void
    {
        $lead = $this->lead();
        $other = $lead->duplicate_of_id !== null ? SalesLead::query()->find($lead->duplicate_of_id) : null;
        if ($other === null || $other->id === $lead->id) {
            $lead->forceFill(['duplicate_of_id' => null])->save();

            return;
        }
        $leads->merge($lead, $other, auth()->user());
        $this->form = $this->formFrom($lead->refresh());
        $this->say('İki kart birleşti; konuşmaların hepsi bu kartta.');
    }

    /** "Farklı kişi": the banner goes and does not come back for this pair. */
    public function notDuplicate(): void
    {
        $lead = $this->lead();
        $lead->forceFill(['duplicate_of_id' => null, 'origin' => array_merge((array) $lead->origin, ['not_duplicate_of' => $lead->duplicate_of_id])])->save();
    }

    /** "Araştır": Claude looks the business up on the web (site, Instagram, harita kaydı); the analysis follows. */
    public function research(): void
    {
        if (! LeadResearcher::queue($this->lead())) {
            $this->say('Bugünlük araştırma sınırı doldu ('.LeadResearcher::DAILY_MAX.'); yarın yeniden denenebilir.');

            return;
        }
        $this->say('Claude araştırıyor; 1-3 dakikada bu kartta.');
    }

    public function writeAnalysis(): void
    {
        if (! SalesAnalysisWriter::queue($this->lead())) {
            $this->say('Bugünlük analiz sınırı doldu ('.SalesAnalysisWriter::DAILY_MAX.'); yarın yeniden yazılabilir.');

            return;
        }
        $this->say('Analiz yazılıyor; 1-2 dakikada bu kartta.');
    }

    /** The analysis's one-finding message goes into the text to send (the operator still edits and sends it). */
    public function useAnalysisMessage(): void
    {
        $lead = $this->lead();
        if (filled($lead->analysis_message)) {
            $this->draft = (string) $lead->analysis_message;
            $lead->forceFill(['draft' => $this->draft])->save();
            $this->say('Mesaj "Gönderilecek mesaj" kutusuna kondu.');
        }
    }

    public function checkSite(): void
    {
        $lead = $this->lead();
        if ($lead->website_host === null) {
            $this->addError('form.website', 'Önce siteyi yaz.');

            return;
        }
        ProspectSiteCheck::queue($lead);
        $this->say('Site kontrol ediliyor; bulgular birkaç saniye içinde burada görünür.');
    }

    public function render(SalesLeads $leads): View
    {
        $lead = $this->lead();
        if (! $this->editing) {
            $this->form = $this->formFrom($lead);
        }
        // A text written in the background (after the site scan or a new message) shows up while the page polls.
        if ($lead->draft_status === 'ready' && trim($this->draft) === '' && filled($lead->draft)) {
            $this->draft = (string) $lead->draft;
        }
        $waText = trim($this->draft);

        $brand = $lead->brand_id !== null ? Brand::query()->find($lead->brand_id) : null;

        return view('livewire.operator.sales.lead-card-page', [
            'lead' => $lead,
            'kinds' => LeadKinds::options(),
            'sectors' => ServiceCategory::query()->orderBy('name')->pluck('name', 'id')->all(),
            'opportunities' => $lead->opportunities()->get(),
            'openOpportunity' => $lead->openOpportunity(),
            'brand' => $brand,
            'terms' => $brand !== null ? app(Agreements::class)->summary($brand) : null,
            'timeline' => $leads->timeline($lead),
            'waUrl' => $lead->waNumber() !== null ? 'https://wa.me/'.$lead->waNumber().($waText !== '' ? '?text='.rawurlencode($waText) : '') : null,
            'drafting' => in_array($lead->draft_status, ['queued', 'running'], true),
            'scan' => is_array($lead->site_scan) ? $lead->site_scan : null,
            'findings' => array_values(array_filter((array) ($lead->findings ?? []), 'is_array')),
            'today' => CarbonImmutable::now('Europe/Istanbul')->toDateString(),
            'isAdmin' => (bool) auth()->user()?->hasRole(Roles::ADMIN),
            'analysing' => in_array($lead->analysis_status, ['queued', 'running'], true),
            'researching' => in_array($lead->research_status, ['queued', 'running'], true),
            'meetings' => Reminder::query()->where('sales_lead_id', $lead->id)->orderByDesc('remind_at')->limit(10)->get(),
            'duplicate' => $lead->duplicate_of_id !== null ? SalesLead::query()->find($lead->duplicate_of_id) : null,
        ]);
    }

    private function lead(): SalesLead
    {
        return SalesLead::query()->findOrFail($this->leadId);
    }

    private function say(string $message): void
    {
        $this->message = $message;
        $this->dispatch('operator-notice', message: $message, tone: 'success');
    }
}
