<?php

namespace App\Services\Access;

use App\Enums\CustomerStatus;
use App\Models\Agreement;
use App\Models\Brand;
use App\Models\Customer;
use App\Models\DigitalAsset;
use App\Support\SchemaState;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Müşteri ayrılma akışı (Bağlantı Düzeni 4.4). One step when a customer leaves: the customer is archived, so no
 * collection, AI, scheduled work or send runs for its brands any more (ServiceScope serves active customers only),
 * its running agreements end today, and the operator gets the list of accesses to give back in the customer's own
 * accounts. Nothing is deleted: the brand's data stays readable, and "Geri al" starts the service again. Nothing here
 * touches an outside account; the agency identity is removed by hand, from the listed places.
 */
final class Departure
{
    /** Where the agency identity is removed, per kind of account the brand holds. */
    private const array RELEASE = [
        'site' => ['name' => 'Site', 'how' => 'WordPress yönetici kullanıcımızı ve bağlayıcı eklentiyi kaldır.', 'where' => null],
        'search_console' => ['name' => 'Search Console', 'how' => 'Ayarlar › Kullanıcılar ve izinler bölümünden hesabımızı çıkar.', 'where' => 'https://search.google.com/search-console'],
        'ga4' => ['name' => 'Analytics', 'how' => 'Yönetici › Hesap erişim yönetimi bölümünden hesabımızı çıkar.', 'where' => 'https://analytics.google.com'],
        'google_ads' => ['name' => 'Google Ads', 'how' => 'Yönetici hesabımızdan bağlantıyı kaldır ya da erişimden çıkar.', 'where' => 'https://ads.google.com'],
        'google_business_profile' => ['name' => 'İşletme Profili', 'how' => 'Kişiler ve erişim bölümünden yöneticilikten ayrıl.', 'where' => 'https://business.google.com'],
        'meta_ads' => ['name' => 'Meta', 'how' => 'Business ayarlarında iş ortaklığını ve varlık erişimlerini kaldır.', 'where' => 'https://business.facebook.com/settings'],
    ];

    /**
     * What the one step covers for this brand's customer, and where it stands.
     *
     * @return array{left: bool, brands: list<string>, agreements: int, release: list<array{name: string, how: string, where: ?string, brand: string}>}
     */
    public function plan(Brand $brand): array
    {
        $customer = $brand->customer;
        $brands = $customer === null ? collect([$brand]) : Brand::query()->where('customer_id', $customer->id)->orderBy('name')->get(['id', 'name']);

        return [
            'left' => $customer?->status === CustomerStatus::Archived,
            'brands' => $brands->pluck('name')->map(fn ($name): string => (string) $name)->all(),
            'agreements' => SchemaState::hasTable('agreements') ? Agreement::query()->whereIn('brand_id', $brands->pluck('id'))->whereIn('status', ['active', 'paused'])->count() : 0,
            'release' => $this->release($brands->pluck('name', 'id')->all()),
        ];
    }

    /**
     * The customer leaves. Returns how many agreements ended.
     */
    public function leave(Customer $customer): int
    {
        return DB::transaction(function () use ($customer): int {
            $customer->forceFill(['status' => CustomerStatus::Archived])->save();
            if (! SchemaState::hasTable('agreements')) {
                return 0;
            }
            $today = CarbonImmutable::now('Europe/Istanbul')->startOfDay();
            $ended = 0;
            $brandIds = Brand::query()->where('customer_id', $customer->id)->pluck('id');
            foreach (Agreement::query()->whereIn('brand_id', $brandIds)->whereIn('status', ['active', 'paused'])->orderBy('id')->get() as $agreement) {
                $agreement->forceFill(['status' => 'ended', 'ends_on' => $agreement->ends_on !== null && $agreement->ends_on->lt($today) ? $agreement->ends_on : $today->toDateString()])->save();
                $ended++;
            }

            return $ended;
        });
    }

    /** The customer came back: service runs again. Ended agreements stay ended; a new one is written for the new term. */
    public function comeBack(Customer $customer): void
    {
        $customer->forceFill(['status' => CustomerStatus::Active])->save();
    }

    /**
     * @param  array<int, string>  $brands  id => name
     * @return list<array{name: string, how: string, where: ?string, brand: string}>
     */
    private function release(array $brands): array
    {
        $out = [];
        $types = DigitalAsset::query()->whereIn('brand_id', array_keys($brands))->orderBy('id')->get(['brand_id', 'type'])->groupBy('brand_id');
        foreach ($brands as $id => $name) {
            $held = $types->get($id, collect())->pluck('type')->unique()->all();
            $keys = [];
            if (in_array('website', $held, true)) {
                $keys = ['site', 'search_console', 'ga4'];
            }
            foreach (['google_ads', 'google_business_profile', 'meta_ads'] as $type) {
                if (in_array($type, $held, true)) {
                    $keys[] = $type;
                }
            }
            foreach ($keys as $key) {
                $out[] = self::RELEASE[$key] + ['brand' => (string) $name];
            }
        }

        return $out;
    }
}
