<?php

namespace App\Livewire\Operator\Sales;

use App\Models\Reminder;
use App\Models\SalesLead;
use App\Services\Sales\ProspectSiteCheck;
use App\Services\Sales\SalesAnalysisWriter;
use App\Services\Sales\SalesLeads;
use App\Services\Sales\SalesReplyWriter;
use App\Services\Sales\SalesShare;
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

    /** "Sohbeti yapıştır": text copied from WhatsApp, and an optional word to Claude ("telefonda fiyat sordu"…). */
    public string $paste = '';

    public string $pasteNote = '';

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
        foreach (['name', 'clinic', 'city', 'branch', 'phone', 'email', 'website', 'source', 'heard_from', 'notes'] as $key) {
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

        return view('livewire.operator.sales.lead-card-page', [
            'lead' => $lead,
            'timeline' => $leads->timeline($lead),
            'waUrl' => $lead->waNumber() !== null ? 'https://wa.me/'.$lead->waNumber().($waText !== '' ? '?text='.rawurlencode($waText) : '') : null,
            'drafting' => in_array($lead->draft_status, ['queued', 'running'], true),
            'scan' => is_array($lead->site_scan) ? $lead->site_scan : null,
            'findings' => array_values(array_filter((array) ($lead->findings ?? []), 'is_array')),
            'today' => CarbonImmutable::now('Europe/Istanbul')->toDateString(),
            'isAdmin' => (bool) auth()->user()?->hasRole(Roles::ADMIN),
            'analysing' => in_array($lead->analysis_status, ['queued', 'running'], true),
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
