<?php

namespace App\Services\Site\Backlinks;

use App\Models\BacklinkSource;
use App\Models\Brand;
use App\Models\DigitalAsset;
use App\Services\Brand\BrandIdentity;
use App\Services\Gbp\Desk\GbpDesk;
use App\Services\SeoTasks\SeoText;

/**
 * NAP kontrolü (Backlink hizmeti mimarisi, katman 5): a listing (künye) helps local search only when it shows the same
 * name, phone and address as the Business Profile. On every check of a listing page its phone numbers and address words
 * are compared with the brand's profiles by rules: phone ok / different / missing, address ok / partial / missing.
 * Anything not ok is written on the row for the operator to fix on the listing.
 */
final class LinkNapCheck
{
    private const array ADDRESS_NOISE = ['mah', 'mahallesi', 'mh', 'cad', 'caddesi', 'cd', 'sok', 'sokak', 'sk', 'sokagi', 'bulvari', 'blv', 'no', 'kat', 'daire',
        'apt', 'apartmani', 'plaza', 'merkezi', 'turkiye', 'turkey'];

    public function __construct(private readonly GbpDesk $gbp) {}

    /**
     * @return array{phone: string, address: string, expected_phone: ?string, found_phones: list<string>, checked_at: string}|null
     *                                                                                                                             null without a Business Profile
     */
    public function check(Brand $brand, string $html): ?array
    {
        $ids = DigitalAsset::query()->where('brand_id', $brand->id)->whereIn('type', ['gbp', 'google_business_profile'])->where('status', '!=', 'archived')
            ->pluck('id')->map(fn ($id): int => (int) $id)->all();

        return self::compare(array_values($this->gbp->snapshots($ids)), $html);
    }

    /**
     * @param  list<array{phone: string, address_text: string}>  $profiles
     * @return array{phone: string, address: string, expected_phone: ?string, found_phones: list<string>, checked_at: string}|null
     */
    public static function compare(array $profiles, string $html): ?array
    {
        if ($profiles === []) {
            return null;
        }
        $text = trim((string) preg_replace('/\s+/u', ' ', strip_tags(str_replace('>', '> ', $html))));
        $found = array_values(array_unique([...BrandIdentity::phones($text), ...BrandIdentity::fromHtml($html)['phones']]));
        $expected = array_values(array_filter(array_map(fn (array $p): ?string => BrandIdentity::phones((string) $p['phone'])[0] ?? null, $profiles)));
        $phone = match (true) {
            $expected === [] => 'bilinmiyor',
            array_intersect($expected, $found) !== [] => 'ok',
            $found === [] => 'yok',
            default => 'farkli',
        };
        $folded = ' '.SeoText::fold($text).' ';
        $best = 0.0;
        foreach ($profiles as $profile) {
            $words = array_values(array_filter(array_unique(preg_split('/[^\p{L}\p{N}]+/u', SeoText::fold((string) $profile['address_text'])) ?: []),
                fn (string $w): bool => mb_strlen($w) >= 3 && ! in_array($w, self::ADDRESS_NOISE, true)));
            if ($words === []) {
                continue;
            }
            $hit = count(array_filter($words, fn (string $w): bool => str_contains($folded, ' '.$w.' ') || str_contains($folded, ' '.$w)));
            $best = max($best, $hit / count($words));
        }
        $address = $best >= 0.6 ? 'ok' : ($best >= 0.3 ? 'eksik' : 'yok');

        return ['phone' => $phone, 'address' => $address, 'expected_phone' => $expected[0] ?? null, 'found_phones' => array_slice($found, 0, 3),
            'checked_at' => now()->toDateString()];
    }

    /** One line for the desk, or null when the listing matches. */
    public static function line(BacklinkSource $row): ?string
    {
        $nap = (array) $row->nap;
        if ($nap === []) {
            return null;
        }
        $out = [];
        if (($nap['phone'] ?? '') === 'farkli') {
            $out[] = 'telefon farklı ('.implode(', ', (array) ($nap['found_phones'] ?? [])).'; doğrusu 0'.($nap['expected_phone'] ?? '').')';
        } elseif (($nap['phone'] ?? '') === 'yok') {
            $out[] = 'telefon yazmıyor';
        }
        if (($nap['address'] ?? '') !== 'ok') {
            $out[] = ($nap['address'] ?? '') === 'eksik' ? 'adres eksik' : 'adres yazmıyor';
        }

        return $out === [] ? null : 'İşletme Profili ile farklı: '.implode(', ', $out).'. Kayıttan düzeltin.';
    }
}
