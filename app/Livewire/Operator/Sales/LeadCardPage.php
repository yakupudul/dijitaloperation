<?php

namespace App\Livewire\Operator\Sales;

use App\Models\SalesLead;
use App\Services\Sales\ProspectSiteCheck;
use App\Services\Sales\SalesDrafts;
use App\Services\Sales\SalesLeads;
use App\Support\Roles;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Kişi kartı: who they are, where they came from, the stage, the next step, the ready text with "WhatsApp'ta aç",
 * the findings, and the timeline of everything (chat messages, notes, reports, stage changes).
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

    public string $lostReason = 'silent';

    public string $message = '';

    public function mount(string $lead): void
    {
        $row = SalesLead::query()->findOrFail((int) $lead);
        $this->leadId = $row->id;
        $this->draft = filled($row->draft) ? (string) $row->draft : SalesDrafts::forLead($row);
        $this->nextStep = (string) $row->next_step;
        $this->nextStepAt = $row->next_step_at?->timezone('Europe/Istanbul')->toDateString() ?? '';
        $this->message = (string) session('sales_notice', '');
        $this->form = $this->formFrom($row);
    }

    private function formFrom(SalesLead $lead): array
    {
        $out = [];
        foreach (['name', 'clinic', 'city', 'branch', 'phone', 'website', 'source', 'notes'] as $key) {
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
        $lead->phone_key = SalesLeads::phoneKey($lead->phone);
        $lead->website_host = SalesLeads::host($lead->website);
        if ($lead->website_host !== null && ! preg_match('#^https?://#i', (string) $lead->website)) {
            $lead->website = 'https://'.ltrim((string) $lead->website, '/');
        }
        $lead->save();
        $this->editing = false;
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

    public function refreshDraft(): void
    {
        $lead = $this->lead();
        $this->draft = SalesDrafts::forLead($lead);
        $lead->forceFill(['draft' => $this->draft])->save();
        $this->say('Metin bulgulara ve adıma göre yeniden yazıldı.');
    }

    public function markSent(SalesLeads $leads): void
    {
        $lead = $this->lead();
        $text = trim($this->draft);
        $leads->markSent($lead, $text !== '' ? $text : null, auth()->user());
        $fresh = $lead->fresh();
        $this->draft = SalesDrafts::forLead($fresh);
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

    public function setStage(string $stage, SalesLeads $leads): void
    {
        $lead = $this->lead();
        if ($stage === 'won') {
            $this->win($leads);

            return;
        }
        $leads->setStage($lead, $stage, $stage === 'lost' ? $this->lostReason : null, auth()->user());
        $fresh = $lead->fresh();
        $this->nextStep = (string) $fresh->next_step;
        $this->nextStepAt = $fresh->next_step_at?->timezone('Europe/Istanbul')->toDateString() ?? '';
        $this->say('Aşama: '.$fresh->stageLabel().'.');
    }

    public function win(SalesLeads $leads): void
    {
        abort_unless(auth()->user()?->hasRole(Roles::ADMIN), 403);
        try {
            $brand = $leads->win($this->lead(), auth()->user());
        } catch (ValidationException $exception) {
            $this->say((string) collect($exception->errors())->flatten()->first());

            return;
        }
        $this->say('Kazanıldı: '.$brand->name.' markası açıldı. Marka sayfasından bağlantıları kurabilirsin.');
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
        if (in_array($lead->findings_status, ['ready', 'failed'], true) && trim($this->draft) === '') {
            $this->draft = SalesDrafts::forLead($lead);
        }
        $waText = trim($this->draft) !== '' ? trim($this->draft) : SalesDrafts::forLead($lead);

        return view('livewire.operator.sales.lead-card-page', [
            'lead' => $lead,
            'timeline' => $leads->timeline($lead),
            'waUrl' => $lead->waNumber() !== null ? 'https://wa.me/'.$lead->waNumber().'?text='.rawurlencode($waText) : null,
            'findings' => array_values(array_filter((array) ($lead->findings ?? []), 'is_array')),
            'today' => CarbonImmutable::now('Europe/Istanbul')->toDateString(),
            'isAdmin' => (bool) auth()->user()?->hasRole(Roles::ADMIN),
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
