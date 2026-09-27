<?php

namespace App\Support\Integrations\Meta;

use App\Models\CoreExternalResource;
use App\Models\CoreIntegration;
use App\Models\DigitalAsset;
use App\Support\Integrations\ProviderRegistry;

/**
 * Thin Meta-specific Binding eligibility (Prompt 23).
 *
 * Shared Binding core stays provider-neutral; this policy only answers
 * whether a Meta Ad Account may be newly bound to a Meta Ads DigitalAsset.
 */
final class MetaBindingEligibilityPolicy
{
    public function assertEligibleResource(CoreExternalResource $resource, ?int $expectedIntegrationId = null): void
    {
        if ($resource->provider !== ProviderRegistry::META) {
            throw new \InvalidArgumentException('Bu işlemle yalnız Meta hesapları bağlanabilir.');
        }

        if ($resource->resource_type !== MetaResourceType::META_AD_ACCOUNT) {
            throw new \InvalidArgumentException(
                'Meta Ads varlığına yalnız bir reklam hesabı bağlanabilir. Meta Business (işletme) yalnız hesapları bulmak içindir; doğrudan bağlanamaz.',
            );
        }

        if ($resource->status !== CoreExternalResource::STATUS_AVAILABLE) {
            throw new \InvalidArgumentException(
                'Bu reklam hesabına şu an erişilemiyor. Hesap listesini yenileyin ya da erişim sorununu giderip tekrar deneyin.',
            );
        }

        if ($expectedIntegrationId !== null && (int) $resource->integration_id !== $expectedIntegrationId) {
            throw new \InvalidArgumentException(
                'Bu reklam hesabı başka bir Meta entegrasyonuna ait; buradan bağlanamaz.',
            );
        }

        $integration = $resource->relationLoaded('integration')
            ? $resource->integration
            : $resource->integration()->first();

        if (! $integration instanceof CoreIntegration) {
            throw new \InvalidArgumentException('Bu hesabın Meta entegrasyonu bulunamadı.');
        }

        if ($integration->provider !== ProviderRegistry::META) {
            throw new \InvalidArgumentException('Bu hesap Meta entegrasyonuna ait değil.');
        }

        if ($integration->status !== CoreIntegration::STATUS_ACTIVE) {
            throw new \InvalidArgumentException('Meta entegrasyonu etkin değil. Önce Entegrasyonlar › Meta sayfasından bağlantıyı etkinleştirin.');
        }

        $selectable = $resource->metadata['selectable'] ?? true;
        $bindable = $resource->metadata['bindable'] ?? true;
        if ($selectable === false || $bindable === false) {
            throw new \InvalidArgumentException('Bu Meta hesabı bağlanmaya uygun değil.');
        }
    }

    public function assertEligibleAsset(DigitalAsset $asset): void
    {
        if ((string) $asset->type !== 'meta_ads') {
            throw new \InvalidArgumentException(
                'Meta reklam hesabı yalnız Meta Ads türündeki bir varlığa bağlanabilir.',
            );
        }
    }

    public function isEligibleForNewBinding(CoreExternalResource $resource, ?int $expectedIntegrationId = null): bool
    {
        try {
            $this->assertEligibleResource($resource, $expectedIntegrationId);

            return true;
        } catch (\InvalidArgumentException) {
            return false;
        }
    }
}
