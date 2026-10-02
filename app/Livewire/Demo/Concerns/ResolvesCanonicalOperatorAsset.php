<?php

namespace App\Livewire\Demo\Concerns;

use App\Models\DigitalAsset;
use App\Services\Operator\OperatorPortfolioPresenter;
use App\Support\Reality\OperatorCanonicalAsset;

trait ResolvesCanonicalOperatorAsset
{
    /**
     * @param  list<string>  $expectedTypes
     */
    protected function bindCanonicalAsset(?string $assetId, array $expectedTypes): DigitalAsset
    {
        $asset = OperatorCanonicalAsset::require($assetId, $expectedTypes);
        $this->assetId = (string) $asset->id;

        return $asset;
    }

    /**
     * @return array<string, mixed>
     */
    protected function presentCanonicalAsset(): array
    {
        return OperatorPortfolioPresenter::asset(
            OperatorCanonicalAsset::require($this->assetId)
        );
    }
}
