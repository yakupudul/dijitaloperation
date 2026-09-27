<?php

namespace App\Livewire\Operator\Portfolio;

use App\Models\Brand;
use App\Models\LeadOutcome;
use App\Services\LeadOutcomes\LeadOutcomeRegistry;
use App\Services\LeadOutcomes\LeadQuality;
use App\Support\Permissions;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;
use Livewire\WithPagination;

/**
 * Marka › Leadler: the brand's client-side leads (imported from the form tool / Meta Lead Center, or added by
 * hand) with one-click outcome buttons and the "Lead kalitesi" block. Lead quality loop — not a CRM.
 */
#[Layout('operator.layouts.app')]
#[Title('Leadler ve sonuçları')]
final class BrandLeads extends Component
{
    use WithFileUploads;
    use WithPagination;

    #[Locked]
    public int $brandId;

    #[Url]
    public string $status = 'new';

    #[Url]
    public int $days = 30;

    public string $importSource = 'meta_lead_form';

    /** @var TemporaryUploadedFile|null */
    public $importFile = null;

    /** @var array{lead_source: string, lead_received_at: string, campaign_label: string, contact_hint: string} */
    public array $manual = ['lead_source' => 'phone_call', 'lead_received_at' => '', 'campaign_label' => '', 'contact_hint' => ''];

    /** @var array<int|string, string> lead id => value (TRY) being edited */
    public array $values = [];

    /** @var array<int|string, string> lead id => note being edited */
    public array $notes = [];

    public ?int $editing = null;

    public string $message = '';

    public function mount(string|int $brand): void
    {
        abort_unless(auth()->user()?->is_active && auth()->user()?->can(Permissions::ACCESS_APP), 403);
        $this->brandId = (int) Brand::query()->findOrFail((int) $brand)->id;
        $this->manual['lead_received_at'] = now()->timezone(config('app.timezone'))->format('Y-m-d');
    }

    public function updatedStatus(): void
    {
        $this->resetPage();
    }

    public function mark(int $id, string $status, LeadOutcomeRegistry $registry): void
    {
        $lead = $this->lead($id);
        $registry->mark($lead, $status, auth()->user(), $lead->value_try !== null ? (float) $lead->value_try : null, $lead->note);
        $this->message = 'Sonuç kaydedildi: '.$lead->statusLabel().'.';
    }

    public function edit(int $id): void
    {
        $lead = $this->lead($id);
        $this->editing = $id;
        $this->values[$id] = $lead->value_try !== null ? (string) (float) $lead->value_try : '';
        $this->notes[$id] = (string) ($lead->note ?? '');
    }

    public function saveDetails(int $id, LeadOutcomeRegistry $registry): void
    {
        $lead = $this->lead($id);
        $raw = str_replace(' ', '', trim((string) ($this->values[$id] ?? '')));
        if (str_contains($raw, ',')) {
            // Turkish format "12.500,50".
            $raw = str_replace(['.', ','], ['', '.'], $raw);
        }
        if ($raw !== '' && ! is_numeric($raw)) {
            $this->addError('values.'.$id, 'Değer sayı olmalı (TL).');

            return;
        }
        try {
            $registry->mark($lead, $lead->status, auth()->user(), $raw !== '' ? (float) $raw : null, (string) ($this->notes[$id] ?? ''));
        } catch (ValidationException $exception) {
            $this->addError('values.'.$id, (string) collect($exception->errors())->flatten()->first());

            return;
        }
        $this->editing = null;
        $this->message = 'Değer ve not kaydedildi.';
    }

    public function remove(int $id): void
    {
        $this->lead($id)->delete();
        $this->message = 'Lead kaydı silindi.';
    }

    public function addManual(LeadOutcomeRegistry $registry): void
    {
        $this->resetValidation();
        try {
            $registry->add($this->brand(), [
                'lead_source' => $this->manual['lead_source'], 'lead_received_at' => $this->manual['lead_received_at'],
                'campaign_label' => $this->manual['campaign_label'], 'contact_hint' => $this->manual['contact_hint'],
            ], auth()->user());
        } catch (ValidationException $exception) {
            foreach ($exception->errors() as $field => $messages) {
                $this->addError('manual.'.$field, $messages[0]);
            }

            return;
        }
        $this->manual['campaign_label'] = '';
        $this->manual['contact_hint'] = '';
        $this->message = 'Lead eklendi; sonucu geldiğinde işaretleyin.';
    }

    public function import(LeadOutcomeRegistry $registry): void
    {
        $this->resetValidation();
        $this->validate(['importFile' => ['required', 'file', 'max:4096', 'mimes:csv,txt,tsv']], [], ['importFile' => 'dosya']);
        try {
            $stats = $registry->import($this->brand(), $this->importSource, (string) $this->importFile->get(), auth()->user());
        } catch (ValidationException $exception) {
            foreach ($exception->errors() as $field => $messages) {
                $this->addError($field, $messages[0]);
            }

            return;
        }
        $this->importFile = null;
        $this->message = sprintf('%d yeni lead eklendi, %d zaten vardı%s. Ad, telefon ve e-posta saklanmadı; eşleştirme için yalnızca baş harfler ve telefonun son 4 hanesi tutuldu.',
            $stats['created'], $stats['existing'], $stats['skipped'] > 0 ? ', '.$stats['skipped'].' satır tarihsiz olduğu için atlandı' : '');
    }

    public function render(LeadQuality $quality): View
    {
        $brand = $this->brand();
        $days = in_array($this->days, [30, 90], true) ? $this->days : 30;
        $to = CarbonImmutable::now(config('app.timezone'));
        $leads = LeadOutcome::query()->where('brand_id', $brand->id)->with('marker')
            ->when(array_key_exists($this->status, LeadOutcome::STATUSES), fn ($q) => $q->where('status', $this->status))
            ->orderByDesc('lead_received_at')->orderByDesc('id')->paginate(30);

        return view('livewire.operator.portfolio.brand-leads', [
            'brand' => $brand,
            'leads' => $leads,
            'quality' => $quality->forBrand($brand, $to->subDays($days - 1), $to),
            'counts' => LeadOutcome::query()->where('brand_id', $brand->id)->selectRaw('status, count(*) as c')->groupBy('status')->pluck('c', 'status')->map(fn ($c): int => (int) $c)->all(),
            'statuses' => LeadOutcome::STATUSES,
            'sources' => LeadOutcome::SOURCES,
            'periodDays' => $days,
        ]);
    }

    private function brand(): Brand
    {
        return Brand::query()->findOrFail($this->brandId);
    }

    private function lead(int $id): LeadOutcome
    {
        return LeadOutcome::query()->where('brand_id', $this->brandId)->findOrFail($id);
    }
}
