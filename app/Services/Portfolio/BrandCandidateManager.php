<?php

namespace App\Services\Portfolio;

use App\Models\Brand;
use App\Models\BrandCandidate;
use App\Models\BrandCandidateResource;
use App\Models\DigitalAsset;
use App\Models\ServiceCategory;
use App\Models\User;
use App\Services\BrandSetup\BrandSetupMatcher;
use Illuminate\Validation\ValidationException;

/**
 * Operator decisions on brand candidates: Onayla (customer → brand with the candidate's sector → assets, through the
 * existing binding services and ownership guard), Düzenle (rename, sector, move a member), Yoksay.
 */
final class BrandCandidateManager
{
    public function __construct(
        private readonly PortfolioGroupCreator $creator,
        private readonly UnassignedWebsites $websites,
        private readonly BrandCandidateBuilder $builder,
    ) {}

    /**
     * @param  array{customer_id?: ?int, customer_name?: ?string, brand_name?: ?string}  $input
     * @return array{brand: Brand, results: list<array{key: string, label: string, ok: bool, message: string}>}
     */
    public function approve(BrandCandidate $candidate, array $input, User $actor): array
    {
        $this->assertProposed($candidate);
        $members = $candidate->members()->with(['resource', 'website'])->get();
        $websites = $members->pluck('website')->filter()->values();
        $resourceIds = $members->pluck('external_resource_id')->filter()->map(fn ($id): int => (int) $id)->values()->all();
        $existingBrandId = data_get($candidate->signals, 'existing_brand_id');
        $firstSite = $websites->first();
        $host = $firstSite instanceof DigitalAsset
            ? BrandSetupMatcher::host((string) ($firstSite->primary_url ?: $firstSite->domain))
            : ($candidate->hosts()[0] ?? '');
        $brandName = trim((string) ($input['brand_name'] ?? '')) ?: (string) $candidate->name;

        $outcome = $this->creator->create([
            'brand_id' => is_numeric($existingBrandId) ? (int) $existingBrandId : null,
            'customer_id' => ! empty($input['customer_id']) ? (int) $input['customer_id'] : null,
            'customer_name' => trim((string) ($input['customer_name'] ?? '')) ?: $brandName,
            'brand_name' => $brandName,
            'website_url' => $host !== '' ? 'https://'.$host.'/' : '',
        ], $resourceIds, $actor);
        $brand = $outcome['brand'];

        // Further brandless websites of the candidate join the same brand.
        foreach ($websites->skip(1) as $site) {
            if ($this->websites->assign($site->fresh() ?? $site, $brand)) {
                $outcome['results'][] = ['key' => 'asset:'.$site->id, 'label' => (string) $site->domain, 'ok' => true, 'message' => 'Web sitesi markaya alındı.'];
            }
        }
        // Sector lives on the brand only; an existing brand keeps the sector it already has.
        if ($brand->sector_id === null && $candidate->sector_id !== null) {
            $brand->forceFill(['sector_id' => $candidate->sector_id])->save();
        }
        $candidate->forceFill([
            'status' => BrandCandidate::APPROVED, 'brand_id' => $brand->id, 'decided_by' => $actor->id, 'decided_at' => now(),
        ])->save();

        return ['brand' => $brand->fresh() ?? $brand, 'results' => $outcome['results']];
    }

    public function dismiss(BrandCandidate $candidate, User $actor): void
    {
        $this->assertProposed($candidate);
        $candidate->forceFill(['status' => BrandCandidate::DISMISSED, 'decided_by' => $actor->id, 'decided_at' => now()])->save();
    }

    public function rename(BrandCandidate $candidate, string $name): void
    {
        $this->assertProposed($candidate);
        $name = trim($name);
        if (mb_strlen($name) < 2 || mb_strlen($name) > 160) {
            throw ValidationException::withMessages(['name' => 'Ad 2–160 karakter olmalı.']);
        }
        $candidate->forceFill(['name' => $name])->save();
    }

    public function setSector(BrandCandidate $candidate, ?int $sectorId): void
    {
        $this->assertProposed($candidate);
        if ($sectorId !== null && ! ServiceCategory::query()->whereKey($sectorId)->exists()) {
            throw ValidationException::withMessages(['sector_id' => 'Sektör katalogda yok.']);
        }
        $candidate->forceFill(['sector_id' => $sectorId, 'sector_signal' => 'manual', 'sector_reason' => null])->save();
    }

    /** Moves one member to another proposed candidate, or (target null) to a new candidate of its own. */
    public function move(BrandCandidateResource $member, ?BrandCandidate $target): BrandCandidate
    {
        $source = $member->candidate;
        $this->assertProposed($source);
        if ($target !== null) {
            $this->assertProposed($target);
        } else {
            $member->loadMissing(['resource', 'website']);
            $name = $member->website !== null
                ? (string) $member->website->domain
                : PortfolioDiscoveryGrouper::cleanName((string) ($member->resource?->display_name ?: $member->resource?->external_id), '');
            $target = BrandCandidate::query()->create([
                'name' => mb_substr($name ?: 'Adsız', 0, 160), 'signals' => [], 'confidence' => 1, 'method' => 'manual', 'status' => BrandCandidate::PROPOSED,
            ]);
        }
        $member->forceFill(['brand_candidate_id' => $target->id, 'reason' => 'Elle taşındı'])->save();
        foreach ([$source, $target] as $candidate) {
            if ($candidate->members()->exists()) {
                $this->builder->refreshSignals($candidate);
            } else {
                $candidate->delete();
            }
        }

        return $target;
    }

    private function assertProposed(BrandCandidate $candidate): void
    {
        if ($candidate->status !== BrandCandidate::PROPOSED) {
            throw ValidationException::withMessages(['candidate' => 'Bu aday zaten karara bağlandı.']);
        }
    }
}
