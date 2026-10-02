<?php

namespace App\Livewire\Operator\Meta;

use App\Livewire\Demo\Meta\OverviewPage as MetaOverviewPage;

/** Operator route of the Meta screen: without an asset id the operator picks the account from the Meta asset list. */
class OverviewPage extends MetaOverviewPage
{
    public function mount(?string $assetId = null, ?string $tab = null): void
    {
        if ($assetId === null || $assetId === '') {
            // Never guess which customer's account to open.
            $this->redirectRoute('operator.assets', ['type' => 'meta_ads'], navigate: true);

            return;
        }

        parent::mount($assetId, $tab);
    }
}
