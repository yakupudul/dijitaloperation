<?php

namespace App\Livewire\Operator\Portfolio;

use App\Livewire\Concerns\AdminOnly;
use App\Models\Agreement;
use App\Models\AgreementPayment;
use App\Models\Brand;
use App\Services\Sales\Agreements;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * Marka › Ayarlar › Anlaşma (yakup 2026-11-07): how this brand works with us — aylık, dönemlik (6 ay) or tek seferlik —
 * the fee, the start, the day of the month it is due, the services, and the monthly ticks. Old brands get it entered
 * here; a won record starts it by itself. The same numbers feed Satış › Müşteriler and the record's card.
 */
final class BrandAgreementCard extends Component
{
    use AdminOnly;

    #[Locked]
    public int $brandId;

    public bool $editing = false;

    /** The agreement being edited (0 = a new one). */
    public int $editingId = 0;

    /** @var array{kind: string, fee: string, term_months: string, starts_on: string, due_day: string, services: array<int, string>, note: string} */
    public array $form = ['kind' => 'monthly', 'fee' => '', 'term_months' => '6', 'starts_on' => '', 'due_day' => '1', 'services' => [], 'note' => ''];

    public string $message = '';

    public function mount(int $brandId): void
    {
        $this->brandId = $brandId;
    }

    public function startNew(): void
    {
        $brand = $this->brand();
        $this->editingId = 0;
        $this->form = ['kind' => 'monthly', 'fee' => $brand->customer?->monthly_fee ? (string) (int) $brand->customer->monthly_fee : '', 'term_months' => '6',
            'starts_on' => CarbonImmutable::now(Agreements::TZ)->startOfMonth()->toDateString(), 'due_day' => '1', 'services' => [], 'note' => ''];
        $this->editing = true;
        $this->resetErrorBag();
    }

    public function edit(int $id): void
    {
        $agreement = Agreement::query()->where('brand_id', $this->brandId)->findOrFail($id);
        $this->editingId = $agreement->id;
        $this->form = ['kind' => $agreement->kind, 'fee' => (string) $agreement->fee, 'term_months' => (string) ($agreement->term_months ?? 6),
            'starts_on' => $agreement->starts_on?->toDateString() ?? '', 'due_day' => (string) $agreement->due_day, 'services' => (array) ($agreement->services ?? []), 'note' => (string) $agreement->note];
        $this->editing = true;
        $this->resetErrorBag();
    }

    public function cancel(): void
    {
        $this->editing = false;
        $this->editingId = 0;
    }

    public function save(Agreements $agreements): void
    {
        $this->resetErrorBag();
        try {
            if ($this->editingId > 0) {
                $agreement = $agreements->update(Agreement::query()->where('brand_id', $this->brandId)->findOrFail($this->editingId), $this->form);
            } else {
                $agreement = $agreements->start($this->brand(), $this->form, auth()->user());
            }
        } catch (ValidationException $exception) {
            $this->setErrorBag($exception->validator->errors());

            return;
        }
        $this->editing = false;
        $this->editingId = 0;
        $this->say('Anlaşma kaydedildi: '.$agreement->feeLabel().'.');
    }

    public function setStatus(int $id, string $status, Agreements $agreements): void
    {
        $agreement = Agreement::query()->where('brand_id', $this->brandId)->findOrFail($id);
        $agreements->setStatus($agreement, $status);
        $this->say('Anlaşma: '.$agreement->fresh()->statusLabel().'.');
    }

    public function togglePaid(int $paymentId, Agreements $agreements): void
    {
        $payment = AgreementPayment::query()->whereKey($paymentId)->whereHas('agreement', fn ($q) => $q->where('brand_id', $this->brandId))->firstOrFail();
        $payment->isPaid() ? $agreements->unpay($payment) : $agreements->markPaid($payment);
        $this->say($payment->periodLabel().': '.($payment->fresh()->isPaid() ? 'ödendi' : 'ödenmedi').'.');
    }

    public function render(Agreements $agreements): View
    {
        $brand = $this->brand();

        return view('livewire.operator.portfolio.brand-agreement-card', [
            'brand' => $brand,
            'terms' => $agreements->summary($brand),
            'history' => Agreement::query()->where('brand_id', $brand->id)->where('status', 'ended')->orderByDesc('id')->limit(5)->get(),
        ]);
    }

    private function brand(): Brand
    {
        return Brand::query()->with('customer')->findOrFail($this->brandId);
    }

    private function say(string $message): void
    {
        $this->message = $message;
        $this->dispatch('operator-notice', message: $message, tone: 'success');
    }
}
