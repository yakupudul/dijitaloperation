<?php

namespace App\Services\BrandSetup;

use App\Jobs\BuildBrandSetupProposalJob;
use App\Models\Brand;
use App\Models\BrandSetupProposal;
use App\Models\User;
use App\Support\ServiceScope;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Orchestrates "Otomatik kur": queue → build (matcher + service suggester) → operator approval.
 */
final class BrandSetupAssistant
{
    public function __construct(
        private readonly BrandSetupMatcher $matcher,
        private readonly BrandSetupServiceSuggester $services,
    ) {}

    public function queue(Brand $brand, string $websiteUrl, ?User $actor): BrandSetupProposal
    {
        app(ServiceScope::class)->ensureBrandServed($brand, 'websiteUrl');
        $host = BrandSetupMatcher::host($websiteUrl);
        if ($host === '' || ! str_contains($host, '.')) {
            throw ValidationException::withMessages(['websiteUrl' => 'Geçerli bir web sitesi adresi girin (ör. ornek.com.tr).']);
        }
        $pending = BrandSetupProposal::query()->where('brand_id', $brand->id)
            ->whereIn('status', [BrandSetupProposal::STATUS_QUEUED, BrandSetupProposal::STATUS_BUILDING])
            ->where('updated_at', '>=', now()->subMinutes(15))->latest('id')->first();
        if ($pending !== null) {
            return $pending;
        }
        // An older run that never finished is closed so the new one is the only one the page follows.
        BrandSetupProposal::query()->where('brand_id', $brand->id)->whereIn('status', [BrandSetupProposal::STATUS_QUEUED, BrandSetupProposal::STATUS_BUILDING])
            ->update(['status' => BrandSetupProposal::STATUS_FAILED, 'error_summary' => 'Zaman aşımı: hazırlık yarım kaldı, yeniden tarandı.', 'updated_at' => now()]);

        $proposal = BrandSetupProposal::query()->create([
            'brand_id' => $brand->id,
            'status' => BrandSetupProposal::STATUS_QUEUED,
            'website_url' => $websiteUrl,
            'created_by' => $actor?->id,
        ]);
        dispatch(new BuildBrandSetupProposalJob($proposal->id))->afterCommit();

        return $proposal;
    }

    public function build(int $proposalId): BrandSetupProposal
    {
        $proposal = BrandSetupProposal::query()->with('brand')->findOrFail($proposalId);
        // Re-checked at handle time: no AI / discovery work for a passive customer's brand.
        if (! app(ServiceScope::class)->isBrandOperational($proposal->brand_id)) {
            $proposal->forceFill(['status' => BrandSetupProposal::STATUS_FAILED, 'error_summary' => ServiceScope::NOT_SERVED])->save();

            return $proposal;
        }
        $proposal->forceFill(['status' => BrandSetupProposal::STATUS_BUILDING])->save();
        try {
            $brand = $proposal->brand;
            $items = $this->matcher->propose($brand, (string) $proposal->website_url);
            $suggestion = $this->services->suggest($brand, BrandSetupMatcher::host((string) $proposal->website_url), $items);
            $proposal->forceFill([
                'status' => BrandSetupProposal::STATUS_READY,
                'items' => $items,
                'services' => $suggestion['services'],
                'services_status' => $suggestion['status'],
                'summary' => $suggestion['summary'],
            ])->save();
        } catch (Throwable $exception) {
            $proposal->forceFill(['status' => BrandSetupProposal::STATUS_FAILED, 'error_summary' => mb_substr($exception->getMessage(), 0, 500)])->save();

            throw $exception;
        }

        return $proposal;
    }
}
