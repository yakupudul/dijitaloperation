<?php

namespace App\Livewire\Operator\Portfolio;

use App\Enums\OfferingStatus;
use App\Jobs\ExtractBrandServicesJob;
use App\Models\Brand;
use App\Models\BrandOffering;
use App\Models\BrandServiceArea;
use App\Models\BrandServiceCandidate;
use App\Models\CoreAssetBinding;
use App\Models\CoreExternalResource;
use App\Models\DigitalAsset;
use App\Models\ServiceCategory;
use App\Models\User;
use App\Services\AiTasks\AiTaskQueue;
use App\Services\BrandIntelligence\BrandOfferingService;
use App\Services\Catalog\BrandCommercialContextService;
use App\Services\Compliance\ForbiddenTermsLibrary;
use App\Services\Integrations\BrandAccountCandidates;
use App\Services\Integrations\ConfirmGoogleResourceBindingService;
use App\Services\Integrations\ConfirmMetaResourceBindingService;
use App\Services\Operator\BrandWorkspaceReadService;
use App\Services\Ownership\OwnershipGuard;
use App\Services\Ownership\OwnershipTransferService;
use App\Services\Portfolio\BrandCandidateBuilder;
use App\Services\Portfolio\BrandServiceExtractor;
use App\Services\Portfolio\UnassignedWebsites;
use App\Support\Ai\AiRouteKeys;
use App\Support\Integrations\ProviderRegistry;
use App\Support\Integrations\ResourceBindingPlan;
use App\Support\Roles;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Throwable;

/**
 * Marka › Ayarlar › Marka bilgileri (`part` = info): sector (the brand's one sector; assets inherit it), service areas
 * (one list, "fiziksel şube"), services — the one place they are edited: ★ = ana (`priority = main`), add, remove, the
 * hub page each is mapped to, "Sayfalardan hizmet çıkar" and its proposals — and the brand's forbidden phrases.
 * Dijital varlıklar (`part` = assets): the brand's assets with bind / unbind / move through the ownership guard and the
 * transfer service. Goals and constraints are edited once, in İş bağlamı (BrandShow).
 */
final class BrandSettings extends Component
{
    #[Locked]
    public int $brandId;

    /** info = Marka bilgileri, assets = Dijital varlıklar › bağlantılar. */
    #[Locked]
    public string $part = 'info';

    public string $sectorId = '';

    public string $areaName = '';

    public string $areaCity = '';

    public string $areaDistrict = '';

    public bool $areaPhysical = false;

    /** "Hizmet ekle": a service by name (found in or added to the catalog). */
    public string $newService = '';

    /** Markaya özel yasaklı ifadeler, one per line (only this brand's content). */
    public string $forbidden = '';

    /** @var array<int|string, string> proposal id => edited name */
    public array $proposalNames = [];

    /** @var array<int|string, string> proposal id => main | secondary */
    public array $proposalPriority = [];

    /** @var array<int|string, bool> proposal id => ticked for "Birleştir" */
    public array $mergePick = [];

    public string $bindChoice = '';

    /** @var array<int|string, string> asset id => target brand id */
    public array $moveTo = [];

    public string $message = '';

    public function mount(int|string $brandId, string $part = 'info'): void
    {
        $brand = Brand::query()->findOrFail((int) $brandId);
        $this->brandId = (int) $brand->id;
        $this->part = $part === 'assets' ? 'assets' : 'info';
        $this->sectorId = $brand->sector_id !== null ? (string) $brand->sector_id : '';
        $this->forbidden = implode("\n", ForbiddenTermsLibrary::brandPhrases($brand));
    }

    public function saveSector(): void
    {
        $this->actor();
        $id = $this->sectorId !== '' ? (int) $this->sectorId : null;
        if ($id !== null && ! ServiceCategory::query()->whereKey($id)->exists()) {
            $this->addError('sectorId', 'Sektör katalogda yok.');

            return;
        }
        $this->brand()->forceFill(['sector_id' => $id])->save();
        $this->message = 'Sektör kaydedildi.';
    }

    public function addArea(BrandCommercialContextService $context): void
    {
        $this->actor();
        $this->validate([
            'areaName' => ['nullable', 'string', 'max:120'],
            'areaCity' => ['required', 'string', 'min:2', 'max:120'],
            'areaDistrict' => ['nullable', 'string', 'max:120'],
        ], ['areaCity.required' => 'İl gerekli.']);
        try {
            $area = $context->addServiceArea($this->brand(), ['country_code' => 'TR', 'city_name' => trim($this->areaCity), 'district_name' => trim($this->areaDistrict)]);
        } catch (ValidationException $exception) {
            $this->addError('areaCity', (string) collect($exception->errors())->flatten()->first());

            return;
        }
        $area->forceFill(['name' => trim($this->areaName) !== '' ? trim($this->areaName) : null, 'physical_branch' => $this->areaPhysical])->save();
        $this->reset('areaName', 'areaCity', 'areaDistrict', 'areaPhysical');
        $this->message = 'Bölge eklendi.';
    }

    public function togglePhysical(int $areaId): void
    {
        $this->actor();
        $area = $this->area($areaId);
        $area->forceFill(['physical_branch' => ! $area->physical_branch])->save();
    }

    public function removeArea(int $areaId): void
    {
        $this->actor();
        $this->area($areaId)->delete();
        $this->message = 'Bölge silindi.';
    }

    public function setPriority(int $offeringId, string $priority): void
    {
        $this->actor();
        abort_unless(array_key_exists($priority, BrandOffering::PRIORITIES), 422);
        $this->offering($offeringId)->forceFill(['priority' => $priority])->save();
    }

    /** ★ on / off (main ↔ secondary). */
    public function togglePriority(int $offeringId): void
    {
        $this->actor();
        $offering = $this->offering($offeringId);
        $offering->forceFill(['priority' => $offering->isMain() ? 'secondary' : 'main'])->save();
    }

    public function addService(BrandOfferingService $offerings): void
    {
        $actor = $this->actor();
        $this->validate(['newService' => ['required', 'string', 'min:2', 'max:255']], ['newService.required' => 'Hizmet adı gerekli.']);
        try {
            $offering = $offerings->resolveOrCreate($this->brand(), trim($this->newService), actor: $actor)['offering'];
        } catch (ValidationException $exception) {
            $this->addError('newService', (string) collect($exception->errors())->flatten()->first());

            return;
        }
        if ($offering->status !== OfferingStatus::Active) {
            $offering->forceFill(['status' => OfferingStatus::Active])->save();
        }
        $this->newService = '';
        $this->message = 'Hizmet eklendi: '.$offering->displayName().'.';
    }

    public function removeOffering(int $offeringId, BrandOfferingService $offerings): void
    {
        $offerings->archive($this->offering($offeringId), $this->actor());
        $this->message = 'Hizmet kaldırıldı.';
    }

    public function extractServices(BrandServiceExtractor $extractor): void
    {
        $this->actor();
        $brand = $this->brand();
        if ($brand->sector_id === null) {
            $this->message = 'Önce sektör seçin.';

            return;
        }
        if ($extractor->candidatePages($brand)->isEmpty()) {
            $this->message = 'Hizmet sayfası yok · önce site toplansın.';

            return;
        }
        ExtractBrandServicesJob::dispatch($brand->id);
        $this->message = app(AiTaskQueue::class)->delegated(AiRouteKeys::BRAND_SERVICES)
            ? 'Hizmet keşfi Claude kuyruğunda · yanıt iş saatlerinde 2 saatte bir gelir.' : 'Hizmet keşfi başladı.';
    }

    public function approveProposal(int $proposalId, BrandServiceExtractor $extractor): void
    {
        $actor = $this->actor();
        $proposal = $this->proposal($proposalId);
        try {
            $extractor->approve($proposal, $actor, $this->proposalNames[$proposalId] ?? null, $this->proposalPriority[$proposalId] ?? 'secondary');
            $this->message = 'Hizmet eklendi.';
        } catch (ValidationException $exception) {
            $this->message = (string) collect($exception->errors())->flatten()->first();
        }
    }

    public function skipProposal(int $proposalId, BrandServiceExtractor $extractor): void
    {
        $this->actor();
        $extractor->skip($this->proposal($proposalId));
    }

    public function mergeProposals(BrandServiceExtractor $extractor): void
    {
        $this->actor();
        $ids = collect($this->mergePick)->filter()->keys()->map(fn ($id): int => (int) $id)->sort()->values();
        if ($ids->count() < 2) {
            $this->message = 'Birleştirmek için en az 2 öneri seçin.';

            return;
        }
        $extractor->merge($this->proposal($ids->first()), $ids->slice(1)->values()->all());
        $this->mergePick = [];
        $this->message = $ids->count().' öneri birleştirildi.';
    }

    public function saveForbidden(ForbiddenTermsLibrary $library): void
    {
        $this->actor();
        $this->validate(['forbidden' => ['nullable', 'string', 'max:10000']]);
        $library->saveBrand($this->brand(), preg_split('/[\r\n,]+/', $this->forbidden) ?: []);
        $this->forbidden = implode("\n", ForbiddenTermsLibrary::brandPhrases($this->brand()));
        $this->message = 'Markaya özel yasaklı ifadeler kaydedildi.';
    }

    public function bind(OwnershipGuard $guard, UnassignedWebsites $websites): void
    {
        $actor = $this->admin();
        $brand = $this->brand();
        [$kind, $id] = array_pad(explode(':', $this->bindChoice, 2), 2, '');
        if ($kind === 'w') {
            $site = DigitalAsset::query()->find((int) $id);
            $this->message = $site !== null && $websites->assign($site, $brand) ? 'Web sitesi bağlandı.' : 'Site başka markaya ait.';
        } elseif ($kind === 'r' && ($resource = CoreExternalResource::query()->find((int) $id)) !== null) {
            $conflict = $guard->forResourceInBrand($resource, $brand);
            if ($conflict !== null) {
                $this->message = $conflict->plainMessage();

                return;
            }
            $plan = new ResourceBindingPlan($resource, $brand, ResourceBindingPlan::MODE_CREATE_ASSET, null,
                mb_substr(BrandCandidateBuilder::typeLabel((string) $resource->resource_type).' · '.($resource->display_name ?: $resource->external_id), 0, 255), $actor);
            try {
                $result = $resource->provider === ProviderRegistry::META
                    ? app(ConfirmMetaResourceBindingService::class)->confirm($plan)
                    : app(ConfirmGoogleResourceBindingService::class)->confirm($plan);
                $this->message = (string) ($result['message'] ?? 'Bağlandı.');
            } catch (ValidationException $exception) {
                $this->message = (string) collect($exception->errors())->flatten()->first();
            }
        }
        $this->bindChoice = '';
    }

    public function unbind(int $bindingId): void
    {
        $actor = $this->admin();
        $binding = CoreAssetBinding::query()->with(['digitalAsset', 'externalResource'])->findOrFail($bindingId);
        abort_unless((int) $binding->digitalAsset?->brand_id === $this->brandId, 404);
        try {
            $result = $binding->externalResource?->provider === ProviderRegistry::META
                ? app(ConfirmMetaResourceBindingService::class)->unbind($binding, $actor)
                : app(ConfirmGoogleResourceBindingService::class)->unbind($binding, $actor);
            $this->message = $result['ok'] ? 'Bağ kaldırıldı.' : (string) $result['message'];
        } catch (Throwable $exception) {
            report($exception);
            $this->message = 'Bağ kaldırılamadı.';
        }
    }

    public function moveAsset(int $assetId, OwnershipTransferService $transfers): void
    {
        $actor = $this->admin();
        $asset = DigitalAsset::query()->where('brand_id', $this->brandId)->findOrFail($assetId);
        $target = Brand::query()->find((int) ($this->moveTo[$assetId] ?? 0));
        if ($target === null) {
            $this->message = 'Hedef marka seçin.';

            return;
        }
        try {
            $transfers->moveAsset($asset, $target, $actor, confirmed: true, note: 'Marka ayarları');
            $this->message = $asset->name.' → '.$target->name;
        } catch (ValidationException $exception) {
            $this->message = (string) collect($exception->errors())->flatten()->first();
        }
    }

    public function render(BrandAccountCandidates $candidates, UnassignedWebsites $websites): View
    {
        $brand = $this->brand();
        $assets = $brand->digitalAssets()->orderBy('type')->orderBy('name')->get();
        $bindings = CoreAssetBinding::query()->with('externalResource')->where('status', CoreAssetBinding::STATUS_ACTIVE)
            ->whereIn('digital_asset_id', $assets->pluck('id'))->get()->groupBy('digital_asset_id');
        $proposals = BrandServiceCandidate::query()->with('catalogItem.primaryName')->where('brand_id', $brand->id)
            ->where('status', BrandServiceCandidate::PROPOSED)->orderBy('name')->get();
        foreach ($proposals as $proposal) {
            $this->proposalNames[$proposal->id] ??= (string) $proposal->name;
            $this->proposalPriority[$proposal->id] ??= 'secondary';
        }
        $isAdmin = (bool) auth()->user()?->hasRole(Roles::ADMIN);
        $offerings = BrandOffering::query()->with(['primaryName', 'catalogItem.primaryName', 'catalogItem.matchingKeywords'])->where('brand_id', $brand->id)
            ->where('status', OfferingStatus::Active->value)->get()->sortBy(fn (BrandOffering $o): string => ($o->isMain() ? '0' : '1').$o->displayName())->values();

        return view('livewire.operator.portfolio.brand-settings', [
            'brand' => $brand,
            'sectors' => ServiceCategory::query()->orderBy('name')->pluck('name', 'id')->all(),
            'areas' => $brand->serviceAreas()->where('status', 'active')->orderBy('priority_rank')->orderBy('id')->get(),
            'offerings' => $offerings,
            'offeringPages' => BrandWorkspaceReadService::offeringPages($offerings->map(fn (BrandOffering $o): int => (int) $o->id)->all()),
            'proposals' => $proposals,
            'assets' => $assets,
            'bindings' => $bindings,
            'bindOptions' => $isAdmin ? collect($candidates->forBrand($brand))->mapWithKeys(fn (array $c): array => ['r:'.$c['resource_id'] => $c['type_label'].' · '.$c['name']])
                ->merge($websites->list()->mapWithKeys(fn (DigitalAsset $s): array => ['w:'.$s->id => 'Web sitesi · '.$s->domain]))->all() : [],
            'brands' => $isAdmin ? Brand::query()->whereKeyNot($brand->id)->orderBy('name')->pluck('name', 'id')->all() : [],
            'isAdmin' => $isAdmin,
        ]);
    }

    private function brand(): Brand
    {
        return Brand::query()->with('sectorCategory')->findOrFail($this->brandId);
    }

    private function area(int $id): BrandServiceArea
    {
        return BrandServiceArea::query()->where('brand_id', $this->brandId)->findOrFail($id);
    }

    private function offering(int $id): BrandOffering
    {
        return BrandOffering::query()->where('brand_id', $this->brandId)->findOrFail($id);
    }

    private function proposal(int $id): BrandServiceCandidate
    {
        return BrandServiceCandidate::query()->where('brand_id', $this->brandId)->findOrFail($id);
    }

    private function actor(): User
    {
        $actor = auth()->user();
        abort_unless($actor instanceof User && $actor->is_active, 403);

        return $actor;
    }

    private function admin(): User
    {
        $actor = $this->actor();
        abort_unless($actor->hasRole(Roles::ADMIN), 403);

        return $actor;
    }
}
