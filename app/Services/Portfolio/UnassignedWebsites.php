<?php

namespace App\Services\Portfolio;

use App\Models\Brand;
use App\Models\DigitalAsset;
use App\Services\BrandSetup\BrandSetupMatcher;
use Illuminate\Support\Collection;
use InvalidArgumentException;

/**
 * Websites added from Integrations before they belong to a brand. They can be connected (WordPress connector) and
 * collected; a brand picks one when it is created, so the site, its connector and its data move together.
 */
final class UnassignedWebsites
{
    /** @return Collection<int, DigitalAsset> */
    public function list(): Collection
    {
        return DigitalAsset::query()->whereNull('brand_id')->where('type', 'website')->orderBy('domain')->get();
    }

    /**
     * A website asset (any brand, or none) at this address; www. and scheme are ignored, the folder counts
     * (kralsoftware.com/newbyangn is not kralsoftware.com). Unassigned sites first.
     */
    public function findByHost(string $url): ?DigitalAsset
    {
        $host = BrandSetupMatcher::host($url);
        if ($host === '') {
            return null;
        }
        $key = BrandSetupMatcher::siteKey($url);

        return DigitalAsset::query()->where('type', 'website')
            ->where(fn ($q) => $q->where('domain', $host)->orWhere('domain', 'www.'.$host)->orWhere('primary_url', 'like', '%://'.$host.'%')->orWhere('primary_url', 'like', '%://www.'.$host.'%'))
            ->orderByRaw('brand_id is null desc')->orderBy('id')->get()
            ->first(fn (DigitalAsset $site): bool => BrandSetupMatcher::assetSiteKey($site) === $key);
    }

    public function add(string $url): DigitalAsset
    {
        $host = BrandSetupMatcher::host($url);
        if ($host === '' || ! str_contains($host, '.')) {
            throw new InvalidArgumentException('Geçerli bir alan adı girin (ör. ornek.com.tr).');
        }
        $key = BrandSetupMatcher::siteKey($url);
        $existing = $this->findByHost($url);
        if ($existing !== null) {
            throw new InvalidArgumentException($existing->brand_id === null
                ? $key.' zaten ekli (markaya bağlı değil).'
                : $key.' zaten bir markaya bağlı: '.($existing->brand?->name ?? '#'.$existing->brand_id).'.');
        }

        // A site in a folder keeps it (www. as typed): https://www.kralsoftware.com/newbyangn.
        $path = BrandSetupMatcher::basePath($url);
        $typedHost = mb_strtolower((string) parse_url(str_contains(trim($url), '://') ? trim($url) : 'https://'.trim($url), PHP_URL_HOST));

        return DigitalAsset::query()->create([
            'brand_id' => null, 'name' => $key, 'type' => 'website', 'status' => 'active', 'module_id' => 'website',
            'domain' => $host, 'primary_url' => $path === '' ? 'https://'.$host : 'https://'.$typedHost.$path,
        ]);
    }

    /** Moves an unassigned website to the brand. A site that already belongs to a brand is left alone. */
    public function assign(DigitalAsset $site, Brand $brand): bool
    {
        if ($site->type !== 'website' || $site->brand_id !== null) {
            return false;
        }
        $site->forceFill(['brand_id' => $brand->id])->save();

        return true;
    }
}
