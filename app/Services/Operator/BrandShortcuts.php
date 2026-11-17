<?php

namespace App\Services\Operator;

use App\Models\Brand;
use App\Models\DigitalAsset;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * "Kısayollar" (arayüz kararı 2026-11-17, yakup: "tak diye erişebilmeliyim"): everything the operator may want to open
 * or copy for one brand, in one menu on the brand page and on the Bugün rows. Four kinds of item:
 *  - link: the provider's own screen in a new tab (ExternalLinks, WordPress signed in),
 *  - page: a MoxDOP screen of this brand,
 *  - copy: a value put on the clipboard (site address, phone, review link),
 *  - missing: what is not connected, greyed, so the gap is seen where it would have been.
 * Failure-safe: a reader that throws drops its group, never the page.
 */
final class BrandShortcuts
{
    public function __construct(private readonly ExternalLinks $links) {}

    /**
     * @return list<array{group: string, items: list<array{kind: string, label: string, hint: string, url: ?string, value: ?string, link: ?array<string, mixed>}>}>
     */
    public function for(Brand $brand): array
    {
        $assets = $this->safely(fn () => DigitalAsset::query()->with('assetBindings.externalResource')->where('brand_id', $brand->id)
            ->whereNotIn('type', ['domain', 'hosting'])->orderBy('id')->get()) ?? collect();
        $external = collect($this->safely(fn (): array => $this->links->forBrand($brand)) ?? [])->keyBy('group');
        $of = fn (string $group): array => array_map(fn (array $link): array => self::link($link), $external[$group]['links'] ?? []);
        $first = fn (string $type): ?DigitalAsset => $assets->firstWhere('type', $type);

        $site = $first('website');
        $profile = $first('google_business_profile');
        $ads = $first('google_ads');
        $meta = $first('meta_ads');
        $placeId = $profile !== null ? $this->placeId($profile) : null;
        $customer = $brand->customer;
        $phone = self::digits((string) ($customer?->primary_phone ?? ''));

        $groups = [
            ['group' => 'Web sitesi', 'items' => $site === null ? [self::missing('Web sitesi bağlı değil')] : [
                ...$of('Web sitesi'), ...$of('WordPress'),
                self::page('Site ekranı', 'Sayfalar, sorunlar ve yazılar', route('operator.website', ['assetId' => $site->id])),
                self::copy('Site adresini kopyala', $this->links->siteBase($site).'/'),
            ]],
            ['group' => 'İşletme profili', 'items' => $profile === null ? [self::missing('İşletme profili bağlı değil')] : array_values(array_filter([
                ...$of('İşletme profili'),
                self::page('Yorumlar ve yanıtlar', 'Gelen yorumlar, yazılan yanıtlar, müşteri onay bağlantısı', route('operator.gbp-reviews', ['marka' => $brand->id])),
                self::page('Profil ekranı', 'Aramalar, yol tarifleri, gönderiler', route('operator.gbp', ['assetId' => $profile->id])),
                $placeId !== null ? self::copy('Yorum isteme bağlantısını kopyala', 'https://search.google.com/local/writereview?placeid='.rawurlencode($placeId)) : null,
            ]))],
            ['group' => 'Reklam', 'items' => array_values(array_filter([
                ...$of('Google Ads'),
                $ads !== null ? self::page('Arama terimleri', 'Para giden aramalar ve negatif önerileri', route('operator.google-ads-terms', ['marka' => $brand->id])) : self::missing('Google Ads bağlı değil'),
                ...$of('Meta'),
                $meta !== null ? self::page('Meta ekranı', 'Kampanyalar ve sonuçlar', route('operator.meta.overview', ['assetId' => $meta->id])) : self::missing('Meta reklam hesabı bağlı değil'),
            ]))],
            ['group' => 'Ölçüm', 'items' => [
                ...($of('Search Console') ?: [self::missing('Search Console bağlı değil')]),
                ...($of('Google Analytics') ?: [self::missing('Google Analytics bağlı değil')]),
            ]],
            ['group' => 'Müşteri', 'items' => $customer === null ? [] : array_values(array_filter([
                self::page('Müşteri kartı', 'Anlaşma, iletişim, markaları', route('operator.customer', ['customerId' => $customer->id])),
                $phone !== '' ? ['kind' => 'link', 'label' => 'WhatsApp’ta yaz', 'hint' => 'Müşteriyle WhatsApp sohbetini aç', 'url' => 'https://wa.me/'.$phone, 'value' => null, 'link' => null] : self::missing('Müşteri telefonu yazılmamış'),
                $phone !== '' ? self::copy('Telefonu kopyala', '+'.$phone) : null,
                self::page('Ödemeler', 'Finans ekranı', route('operator.finance')),
            ]))],
            ['group' => 'Marka', 'items' => [
                self::page('Dosyalar', 'Logo, görseller, belgeler', route('operator.files', ['brand' => $brand->id])),
                self::page('Yapılacaklar', 'Bu markada sana kalan işler', route('operator.calendar')),
                self::page('Yapılan işler', 'Claude’un bu markada yaptıkları', route('operator.flow')),
                self::page('Marka bilgileri', 'Hizmetler, bölge, iş bağlamı', route('operator.brand', ['brand' => $brand->id, 'tab' => 'ayarlar'])),
            ]],
        ];

        return array_values(array_filter($groups, fn (array $group): bool => $group['items'] !== []));
    }

    /** Short row of doors for a list row (Bugün): site, WordPress, profile, ads; at most four. */
    public function doors(Brand $brand): array
    {
        $out = [];
        foreach ($this->for($brand) as $group) {
            foreach ($group['items'] as $item) {
                if ($item['kind'] === 'link' && $item['link'] !== null && count($out) < 4 && ! in_array($group['group'], array_column($out, 'group'), true)) {
                    $out[] = ['group' => $group['group'], ...$item];
                }
            }
        }

        return $out;
    }

    /** @param array<string, mixed> $link */
    private static function link(array $link): array
    {
        return ['kind' => 'link', 'label' => (string) $link['label'], 'hint' => (string) $link['hint'], 'url' => (string) $link['url'], 'value' => null, 'link' => $link];
    }

    private static function page(string $label, string $hint, string $url): array
    {
        return ['kind' => 'page', 'label' => $label, 'hint' => $hint, 'url' => $url, 'value' => null, 'link' => null];
    }

    private static function copy(string $label, string $value): array
    {
        return ['kind' => 'copy', 'label' => $label, 'hint' => $value, 'url' => null, 'value' => $value, 'link' => null];
    }

    private static function missing(string $label): array
    {
        return ['kind' => 'missing', 'label' => $label, 'hint' => 'Bağlanınca burada açılır', 'url' => null, 'value' => null, 'link' => null];
    }

    private function placeId(DigitalAsset $profile): ?string
    {
        return $this->safely(function () use ($profile): ?string {
            $ids = $profile->assetBindings->where('status', 'active')->pluck('external_resource_id')->filter()->all();
            if ($ids === []) {
                return null;
            }
            $place = DB::table('gbp_location_snapshots')->whereIn('external_resource_id', $ids)->whereNotNull('place_id')->orderByDesc('captured_at')->orderByDesc('id')->value('place_id');

            return is_string($place) && trim($place) !== '' ? trim($place) : null;
        });
    }

    /** Turkish numbers to international digits: 0538… → 90538…; anything else stays as written. */
    private static function digits(string $value): string
    {
        $digits = preg_replace('/\D+/', '', $value) ?? '';
        if ($digits === '') {
            return '';
        }
        if (str_starts_with($digits, '0') && strlen($digits) === 11) {
            return '9'.$digits;
        }

        return strlen($digits) === 10 ? '90'.$digits : $digits;
    }

    private function safely(callable $read): mixed
    {
        try {
            return $read();
        } catch (Throwable $error) {
            report($error);

            return null;
        }
    }
}
