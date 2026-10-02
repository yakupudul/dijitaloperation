<?php

namespace App\Http\Controllers\Operator;

use App\Support\Reality\OperatorCanonicalAsset;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Old per-entity Meta Ads pages (campaigns, ad sets, ads, creatives, breakdowns, insights and their
 * detail pages) now live as tabs of the single Meta Ads asset page. Route names are kept so older
 * links keep working; every one of them lands on the matching tab.
 */
final class MetaLegacyPageRedirectController
{
    public function __invoke(Request $request): RedirectResponse
    {
        $asset = OperatorCanonicalAsset::require((string) $request->route('assetId'), ['meta_ads']);

        $parameters = ['assetId' => (string) $asset->id, 'tab' => (string) $request->route('tab', 'overview')];

        $level = $request->route('level');
        if (is_string($level) && $level !== '' && $level !== 'campaigns') {
            $parameters['level'] = $level;
        }

        return new RedirectResponse(route('operator.meta.overview', $parameters), 302);
    }
}
