<?php

namespace App\Livewire\Operator\Portfolio;

use App\Models\Brand;
use App\Models\BrandSetupProposal;
use App\Models\DigitalAsset;
use App\Services\BrandSetup\BrandSetupApplier;
use App\Services\BrandSetup\BrandSetupAssistant;
use App\Support\Permissions;
use App\Support\Roles;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * /brands/{brand}/setup — "Otomatik kur": propose website asset, account bindings and services;
 * the operator ticks/unticks and approves with one click.
 */
#[Layout('operator.layouts.app')]
#[Title('Otomatik kur')]
final class BrandSetupPage extends Component
{
    #[Locked]
    public int $brandId;

    public string $websiteUrl = '';

    /** @var array<string, bool> */
    public array $selectedItems = [];

    /** @var array<int, bool> */
    public array $selectedServices = [];

    public ?int $loadedProposalId = null;

    public string $message = '';

    public string $messageTone = 'success';

    public function mount(string $brand): void
    {
        abort_unless(auth()->user()?->is_active && auth()->user()?->can(Permissions::ACCESS_APP), 403);
        $model = Brand::query()->findOrFail((int) $brand);
        $this->brandId = $model->id;
        $website = $model->digitalAssets()->where('type', 'website')->orderBy('id')->first();
        $this->websiteUrl = (string) ($website?->primary_url ?: $website?->domain ?: '');

        // Coming from "new brand" with a website: start the proposal immediately.
        $url = trim((string) request()->query('url', ''));
        if ($url !== '' && $this->latest() === null) {
            $this->websiteUrl = $url;
            $this->start(app(BrandSetupAssistant::class));
        }
    }

    public function start(BrandSetupAssistant $assistant): void
    {
        try {
            $proposal = $assistant->queue($this->brand(), $this->websiteUrl, auth()->user());
        } catch (ValidationException $exception) {
            $this->addError('websiteUrl', $exception->validator->errors()->first('websiteUrl'));

            return;
        }
        $this->loadedProposalId = null;
        $this->flash($proposal->isPending() ? 'Öneriler hazırlanıyor. Hesaplar ve site verisi taranıyor; sayfayı kapatabilirsin.' : 'Öneriler hazır.');
    }

    public function selectAll(bool $value = true): void
    {
        $proposal = $this->latest();
        foreach ($proposal?->items ?? [] as $item) {
            if ($item['status'] === 'proposed') {
                $this->selectedItems[$item['key']] = $value;
            }
        }
        foreach ($proposal?->services ?? [] as $index => $service) {
            if (in_array($service['status'], ['proposed', 'already'], true)) {
                $this->selectedServices[$index] = $value;
            }
        }
    }

    public function approve(BrandSetupApplier $applier): void
    {
        abort_unless(auth()->user()?->hasRole(Roles::ADMIN), 403);
        $proposal = $this->latest();
        if ($proposal === null || $proposal->status !== BrandSetupProposal::STATUS_READY) {
            return;
        }
        $keys = array_keys(array_filter($this->selectedItems));
        $services = array_map('intval', array_keys(array_filter($this->selectedServices)));
        if ($keys === [] && $services === []) {
            $this->flash('Onaylanacak bir şey seçilmedi.', 'error');

            return;
        }
        $results = $applier->apply($proposal, auth()->user(), $keys, $services);
        $failed = count(array_filter($results, fn (array $r): bool => ! $r['ok']));
        $this->flash(sprintf('%d işlem uygulandı%s.', count($results) - $failed, $failed > 0 ? ', '.$failed.' işlem yapılamadı (ayrıntılar aşağıda)' : ''), $failed > 0 ? 'error' : 'success');
    }

    public function render(): View
    {
        $brand = $this->brand();
        $proposal = $this->latest();
        if ($proposal !== null && $proposal->status === BrandSetupProposal::STATUS_READY && $this->loadedProposalId !== $proposal->id) {
            $this->loadedProposalId = $proposal->id;
            $this->selectedItems = [];
            foreach ($proposal->items ?? [] as $item) {
                $this->selectedItems[$item['key']] = (bool) ($item['selected'] ?? false);
            }
            $this->selectedServices = [];
            foreach ($proposal->services ?? [] as $index => $service) {
                $this->selectedServices[$index] = (bool) ($service['selected'] ?? false);
            }
        }

        return view('livewire.operator.portfolio.brand-setup-page', [
            'brand' => $brand,
            'proposal' => $proposal,
            'assets' => DigitalAsset::query()->where('brand_id', $brand->id)->get()->keyBy('id'),
        ]);
    }

    private function brand(): Brand
    {
        return Brand::query()->findOrFail($this->brandId);
    }

    private function latest(): ?BrandSetupProposal
    {
        return BrandSetupProposal::query()->where('brand_id', $this->brandId)->latest('id')->first();
    }

    private function flash(string $message, string $tone = 'success'): void
    {
        $this->message = $message;
        $this->messageTone = $tone;
    }
}
