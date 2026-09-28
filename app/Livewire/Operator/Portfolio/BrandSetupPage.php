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
use Throwable;

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

    /** Fill the brand's İş bağlamı from the site (only empty fields). */
    public bool $applyContext = true;

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
        $url = is_string($raw = request()->query('url')) ? trim($raw) : '';
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
        foreach ($proposal?->itemRows() ?? [] as $item) {
            if ($item['status'] === 'proposed') {
                $this->selectedItems[$item['key']] = $value;
            }
        }
        foreach ($proposal?->serviceRows() ?? [] as $index => $service) {
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
            $this->flash($proposal?->status === BrandSetupProposal::STATUS_APPLIED ? 'Bu öneri zaten uygulandı; sonuçlar aşağıda.' : 'Onaylanacak hazır bir öneri yok.', 'error');

            return;
        }
        $keys = array_map('strval', array_keys(array_filter($this->selectedItems)));
        $services = array_map('intval', array_keys(array_filter($this->selectedServices)));
        if ($keys === [] && $services === [] && ! ($this->applyContext && is_array(data_get($proposal->summary, 'business_context')))) {
            $this->flash('Onaylanacak bir şey seçilmedi.', 'error');

            return;
        }
        try {
            $results = $applier->apply($proposal, auth()->user(), $keys, $services, $this->applyContext);
        } catch (Throwable $exception) {
            report($exception);
            $this->flash('Öneri uygulanırken beklenmeyen bir hata oluştu; hiçbir dış platforma yazılmadı. Sayfayı yenileyip sonuçları kontrol edin.', 'error');

            return;
        }
        $failed = count(array_filter($results, fn (array $r): bool => ! $r['ok']));
        $applied = count($results) - $failed;
        $this->flash(match (true) {
            $failed === 0 => sprintf('%d işlem uygulandı.', $applied),
            $applied === 0 => sprintf('Hiçbir işlem uygulanamadı (%d işlem yapılamadı); nedenleri aşağıda.', $failed),
            default => sprintf('Kısmen uygulandı: %d işlem uygulandı, %d işlem yapılamadı (ayrıntılar aşağıda).', $applied, $failed),
        }, $failed > 0 ? 'error' : 'success');
    }

    public function render(): View
    {
        $brand = $this->brand();
        $proposal = $this->latest();
        if ($proposal !== null && $proposal->status === BrandSetupProposal::STATUS_READY && $this->loadedProposalId !== $proposal->id) {
            $this->loadedProposalId = $proposal->id;
            $this->selectedItems = [];
            foreach ($proposal->itemRows() as $item) {
                $this->selectedItems[$item['key']] = (bool) ($item['selected'] ?? false);
            }
            $this->selectedServices = [];
            foreach ($proposal->serviceRows() as $index => $service) {
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
