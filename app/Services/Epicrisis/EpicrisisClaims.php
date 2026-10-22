<?php

namespace App\Services\Epicrisis;

use App\Models\Brand;
use App\Models\BrandEpicrisisItem;
use App\Models\BrandMemory;

/**
 * Bilgi doğrulama (marka epikrizi): before a text MoxDOP wrote by itself reaches the daily one-click send, its claims
 * about the business are compared by rules with the brand's record, the facts read from its own pages and the text's
 * own source: phone numbers,
 * e-mails, prices, "N yıl garanti", "N yıllık deneyim" / "since 2005" and "N hasta / vaka". A claim that neither holds
 * is invented: the text stays on the desk with the claim named. Durations of a treatment ("3 ay sürer") are general
 * knowledge, not checked.
 *
 * Audit Y5 (2026-10-22): also a success rate ("%98 başarı"), a speed the business promises ("3 günde", "24 saat
 * içinde") and a superiority claim ("İzmir'in en iyi kliniği", "1 numaralı", "best dentist") — the writer and its AI
 * check read the same sources, so these are held by rule, not left to the check.
 */
final class EpicrisisClaims
{
    private const string PHONE = '/(?<!\d)(?:\+?90[\s.\-]?)?\(?0?\s?[2-5]\d{2}\)?[\s.\-]?\d{3}[\s.\-]?\d{2}[\s.\-]?\d{2}(?!\d)/u';

    private const string EMAIL = '/[A-Za-z0-9._%+\-]+@[A-Za-z0-9.\-]+\.[A-Za-z]{2,}/u';

    /** Number-bearing claims: [pattern, label, number group]. */
    private const array NUMBERED = [
        ['/(?:₺|€|£|\$)\s?(\d[\d.,]*)|(\d[\d.,]*)\s?(?:TL|₺|€|£|\$|EUR|GBP|USD|euro|pound|lira)\b/iu', 'fiyat'],
        ['/(\d+)\s*(?:-|–)?\s*(?:yıl|yil|year|jahr)\w*\s+(?:\p{L}+\s+)?(?:garanti|warranty|garantie)/iu', 'garanti'],
        ['/(?:garanti|warranty|garantie)\w*\s+(?:\p{L}+\s+){0,3}(\d+)\s*(?:yıl|yil|year|jahr)/iu', 'garanti'],
        ['/(\d+)\s*(?:\+\s*)?(?:yıl|yil|year|jahr)\w*\s+(?:\p{L}+\s+)?(?:deneyim|tecrübe|tecrube|experience|erfahrung)/iu', 'deneyim'],
        ['/(?:since|seit|beri)\s+((?:19|20)\d{2})|((?:19|20)\d{2})\s*(?:yılından|yilindan|\'?(?:den|dan|ten|tan))\s+(?:bu\s+yana|beri)/iu', 'kuruluş yılı'],
        ['/(\d[\d.,]*)\s*\+?\s*(?:\'?(?:den|dan|ten|tan)\s+fazla\s+)?(?:mutlu\s+)?(?:hasta|vaka|patient|case|müşteri|musteri|customer)\w*/iu', 'hasta / vaka sayısı'],
        ['/%\s?(\d+(?:[.,]\d+)?)\s*(?:\'?(?:lik|lık|luk|lük))?\s*(?:\p{L}+\s+)?(?:başarı|basari|success|erfolg)|(?:başarı|basari|success|erfolg)\w*\s+(?:oranı|orani|rate|quote)?\s*%\s?(\d+(?:[.,]\d+)?)|(\d+(?:[.,]\d+)?)\s?%\s*(?:\p{L}+\s+)?(?:success|erfolg)/iu', 'başarı oranı'],
        ['/(?<![\d.,])(\d+)\s*(?:saat|gün|gun|dakika|hafta)(?:\s*(?:içinde|icinde)|\'?(?:te|ta|de|da))(?!\p{L})(?!\s+bir\b)|\b(?:in|within)\s+(\d+)\s+(?:hours?|days?|minutes?|weeks?)\b/iu', 'süre'],
    ];

    /** Superiority claims without a number: held unless the brand's own words say the same. */
    private const string SUPERIORITY = '/(?:\p{L}+\'(?:nin|nın|nun|nün|in|ın|un|ün)\s+)?en\s+(?:iyi|ucuz|uygun|büyük|buyuk|güvenilir|guvenilir|deneyimli|başarılı|basarili)\s+(?:\p{L}+\s+)?(?:klini\p{L}*|doktor\p{L}*|hekim\p{L}*|hastane\p{L}*|merkez\p{L}*|firma\p{L}*|şirket\p{L}*|ekib\p{L}*|ekip\p{L}*|hizmet\p{L}*|fiyat\p{L}*)|(?:1|bir)\s+numaralı|\bbest\s+(?:\p{L}+\s+)?(?:clinic|dentist|doctor|surgeon|hospital|price|team)s?\b|\bnumber\s+one\b|\bthe\s+cheapest\b/iu';

    /**
     * Claims of the text the brand's record and the given sources do not hold.
     *
     * @param  list<string>  $sources  the text's own source (its plan, page, idea): what it may repeat
     * @return list<string> e.g. ["telefon 0232 111 22 33", "garanti: 15 yıl"]
     */
    public function unsupported(Brand $brand, string $text, array $sources = []): array
    {
        $text = html_entity_decode(strip_tags((string) preg_replace('/<(br|\/p|\/li|\/h\d)>/i', ' $0', $text)), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $facts = BrandEpicrisisItem::query()->where('brand_id', $brand->id)->whereIn('layer', [BrandEpicrisisItem::FACT, BrandEpicrisisItem::EVIDENCE])
            ->whereIn('status', [BrandEpicrisisItem::ACTIVE])->get(['field', 'value', 'source_text']);
        // The facts MoxDOP read from the brand's own pages (page memory) are its own words too.
        $pages = BrandMemory::query()->where('brand_id', $brand->id)->where('kind', 'page')->orderByDesc('id')->limit(1500)->get(['data'])
            ->map(fn (BrandMemory $m): string => implode(' ', array_map('strval', (array) data_get($m->data, 'facts', []))))->implode(' ');
        $known = $facts->map(fn (BrandEpicrisisItem $i): string => $i->value.' '.$i->source_text)->implode(' ').' '.$pages.' '.implode(' ', $sources);
        $knownPhones = [];
        preg_match_all(self::PHONE, $known, $m);
        foreach ([...$m[0], ...$facts->where('field', 'phone')->pluck('value')->all()] as $phone) {
            $knownPhones[EpicrisisStore::phone((string) $phone)] = true;
        }
        $knownDigits = ' '.(string) preg_replace('/[^\d]+/', ' ', str_replace(['.', ','], '', $known)).' ';
        $out = [];
        preg_match_all(self::PHONE, $text, $m);
        foreach (array_unique($m[0]) as $phone) {
            if (strlen((string) preg_replace('/\D/', '', $phone)) >= 10 && ! isset($knownPhones[EpicrisisStore::phone($phone)])) {
                $out[] = 'telefon '.trim($phone);
            }
        }
        preg_match_all(self::EMAIL, $text, $m);
        foreach (array_unique($m[0]) as $mail) {
            if (! str_contains(mb_strtolower($known), mb_strtolower($mail))) {
                $out[] = 'e-posta '.$mail;
            }
        }
        foreach (self::NUMBERED as [$pattern, $label]) {
            preg_match_all($pattern, $text, $matches, PREG_SET_ORDER);
            foreach ($matches as $match) {
                $number = collect(array_slice($match, 1))->first(fn ($g): bool => is_string($g) && $g !== '');
                if ($number === null) {
                    continue;
                }
                $digits = str_replace(['.', ','], '', (string) $number);
                // "2 hasta", "%95 vakada" are not counts the business claims; "1.000'den fazla hasta" is.
                if ($label === 'hasta / vaka sayısı' && (int) $digits < 100) {
                    continue;
                }
                if ($digits !== '' && ! str_contains($knownDigits, ' '.$digits.' ')) {
                    $out[] = $label.': '.trim($match[0]);
                }
            }
        }

        preg_match_all(self::SUPERIORITY, $text, $m);
        $knownLower = mb_strtolower($known);
        foreach (array_unique($m[0]) as $phrase) {
            if (! str_contains($knownLower, mb_strtolower(trim($phrase)))) {
                $out[] = 'üstünlük iddiası: '.trim($phrase);
            }
        }

        return array_values(array_unique($out));
    }

    /**
     * The reason a held text shows: "Kaynağı olmayan bilgi: telefon 0232 …, garanti: 15 yıl garanti".
     *
     * @param  list<string>  $claims
     */
    public static function reason(array $claims): string
    {
        return mb_substr('Kaynağı olmayan bilgi: '.implode(', ', array_slice($claims, 0, 4)).' (marka epikrizinde ve kaynağında yok).', 0, 200);
    }
}
