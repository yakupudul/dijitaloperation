<?php

namespace App\Services\Prospects;

use App\Models\Brand;
use App\Models\Customer;
use App\Models\DigitalAsset;
use App\Models\Prospect;

/**
 * Deterministic duplicate detection for Prospect conversion.
 * Strong signals only — no fuzzy name merge.
 */
final class ProspectDuplicateDetector
{
    /**
     * @return array{
     *     customers: list<array<string, mixed>>,
     *     brands: list<array<string, mixed>>,
     *     digital_assets: list<array<string, mixed>>
     * }
     */
    public function find(Prospect $prospect): array
    {
        $assets = $this->assets($prospect);
        $customers = $this->customers($prospect);
        $brands = $this->brands($prospect, $customers);

        foreach ($assets as $asset) {
            $brand = Brand::query()->with('customer')->find($asset['brand_id'] ?? null);
            if (! $brand instanceof Brand) {
                continue;
            }

            $brands[] = [
                'id' => $brand->id,
                'name' => $brand->name,
                'customer_id' => $brand->customer_id,
            ];

            if ($brand->customer instanceof Customer) {
                $customers[] = [
                    'id' => $brand->customer->id,
                    'name' => $brand->customer->name,
                    'primary_email' => $brand->customer->primary_email,
                    'primary_phone' => $brand->customer->primary_phone,
                ];
            }
        }

        return [
            'customers' => collect($customers)->unique('id')->values()->all(),
            'brands' => collect($brands)->unique('id')->values()->all(),
            'digital_assets' => $assets,
        ];
    }

    public static function normalizeDomain(?string $url): ?string
    {
        if ($url === null || trim($url) === '') {
            return null;
        }

        $candidate = trim($url);
        if (! str_contains($candidate, '://')) {
            $candidate = 'https://'.$candidate;
        }

        $host = parse_url($candidate, PHP_URL_HOST);
        if (! is_string($host) || $host === '') {
            return null;
        }

        $host = strtolower($host);

        return str_starts_with($host, 'www.') ? substr($host, 4) : $host;
    }

    public static function normalizePhone(?string $phone): ?string
    {
        if ($phone === null || trim($phone) === '') {
            return null;
        }

        $digits = preg_replace('/\D+/', '', $phone);

        return is_string($digits) && strlen($digits) >= 7 ? $digits : null;
    }

    public static function normalizeName(?string $name): ?string
    {
        if ($name === null) {
            return null;
        }

        $trimmed = mb_strtolower(trim($name));

        return $trimmed === '' ? null : $trimmed;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function customers(Prospect $prospect): array
    {
        $matches = collect();

        $email = is_string($prospect->contact_email) ? mb_strtolower(trim($prospect->contact_email)) : null;
        if ($email) {
            $matches = $matches->merge(Customer::query()->whereRaw('lower(primary_email) = ?', [$email])->get());
        }

        $phone = self::normalizePhone($prospect->contact_phone);
        if ($phone !== null) {
            $matches = $matches->merge(
                Customer::query()
                    ->whereNotNull('primary_phone')
                    ->get()
                    ->filter(fn (Customer $customer): bool => self::normalizePhone($customer->primary_phone) === $phone)
            );
        }

        $name = self::normalizeName($prospect->company_name);
        if ($name !== null) {
            $matches = $matches->merge(
                Customer::query()
                    ->get()
                    ->filter(fn (Customer $customer): bool => self::normalizeName($customer->name) === $name
                        || self::normalizeName($customer->legal_name) === $name)
            );
        }

        return $matches
            ->unique('id')
            ->map(fn (Customer $customer): array => [
                'id' => $customer->id,
                'name' => $customer->name,
                'primary_email' => $customer->primary_email,
                'primary_phone' => $customer->primary_phone,
            ])
            ->values()
            ->all();
    }

    /**
     * @param  list<array<string, mixed>>  $customers
     * @return list<array<string, mixed>>
     */
    private function brands(Prospect $prospect, array $customers): array
    {
        $matches = collect();
        $name = self::normalizeName($prospect->company_name);
        if ($name !== null) {
            $matches = $matches->merge(
                Brand::query()
                    ->get()
                    ->filter(fn (Brand $brand): bool => self::normalizeName($brand->name) === $name)
            );
        }

        foreach ($customers as $row) {
            $matches = $matches->merge(Brand::query()->where('customer_id', $row['id'])->get());
        }

        return $matches
            ->unique('id')
            ->map(fn (Brand $brand): array => [
                'id' => $brand->id,
                'name' => $brand->name,
                'customer_id' => $brand->customer_id,
            ])
            ->values()
            ->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function assets(Prospect $prospect): array
    {
        $domain = self::normalizeDomain($prospect->website_url);
        if ($domain === null) {
            return [];
        }

        return DigitalAsset::query()
            ->where('type', 'website')
            ->get()
            ->filter(function (DigitalAsset $asset) use ($domain): bool {
                $fromUrl = self::normalizeDomain($asset->primary_url);
                $fromDomain = self::normalizeDomain($asset->domain);

                return $fromUrl === $domain || $fromDomain === $domain;
            })
            ->map(fn (DigitalAsset $asset): array => [
                'id' => $asset->id,
                'name' => $asset->name,
                'brand_id' => $asset->brand_id,
                'type' => $asset->type,
                'primary_url' => $asset->primary_url,
                'domain' => $asset->domain,
            ])
            ->values()
            ->all();
    }
}
