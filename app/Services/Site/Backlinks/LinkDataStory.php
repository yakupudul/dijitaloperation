<?php

namespace App\Services\Site\Backlinks;

use App\Models\BacklinkSource;
use App\Models\Brand;
use App\Models\DigitalAsset;
use App\Models\LinkSource;
use App\Services\SeoTasks\SeoText;
use App\Services\Site\BrandlessSites;
use Illuminate\Support\Facades\DB;

/**
 * Veri haberi (Backlink hizmeti mimarisi, motor 7): once a quarter, the questions people asked Google before reaching
 * the brand's site (its own Search Console, last 90 days) become a short press text for local news ("Ankara'da
 * hastaların en çok merak ettiği 10 implant sorusu ve uzman yanıtları"). Local news sites link to the source of such
 * a story. Only the brand's own data is used, as an order (most asked first) without its numbers; nothing is invented.
 * The row lands on the desk like any other; the operator sends it to the local news sources of the brand's city.
 */
final class LinkDataStory
{
    public const int MIN_QUESTIONS = 5;

    public const int DAYS = 90;

    /** Folded question words of Turkish searches. */
    private const string QUESTION = '/(^|\s)(ne|neden|nasil|kac|hangi|kim|kimler|nedir|mi|mu|midir|mudur|nelerdir)(\s|$)/u';

    /** @return list<string> the brand's most asked questions of the last DAYS days, most asked first */
    public static function questions(Brand $brand, int $limit = 10): array
    {
        $sites = DigitalAsset::query()->where('brand_id', $brand->id)->where('type', 'website')->where('status', 'active')->get()
            ->filter(fn (DigitalAsset $s): bool => BrandlessSites::serviced($s))->pluck('id')->all();
        if ($sites === []) {
            return [];
        }
        $name = SeoText::fold((string) $brand->name);
        $nameWords = array_filter(preg_split('/\s+/u', $name) ?: [], fn (string $w): bool => mb_strlen($w) >= 4);
        $rows = DB::table('gsc_query_page_daily')->whereIn('digital_asset_id', $sites)->where('reporting_date', '>=', now()->subDays(self::DAYS)->toDateString())
            ->groupBy('query')->selectRaw('query, sum(impressions) as n')->orderByDesc('n')->limit(2000)->get();
        $out = [];
        foreach ($rows as $row) {
            $query = trim((string) $row->query);
            $folded = SeoText::fold($query);
            if (mb_strlen($folded) < 12 || array_filter($nameWords, fn (string $w): bool => str_contains($folded, $w)) !== []) {
                continue; // too short, or the brand's own name
            }
            if (preg_match(self::QUESTION, $folded) === 1) {
                $out[$folded] = $query;
            }
            if (count($out) >= $limit) {
                break;
            }
        }

        return array_values($out);
    }

    /** Key of the quarter, e.g. 2026q4. */
    public static function quarter(): string
    {
        $now = now('Europe/Istanbul');

        return $now->format('Y').'q'.$now->quarter;
    }

    /**
     * The quarter's data story row of a brand (once a quarter, only with enough questions).
     */
    public static function propose(Brand $brand): ?BacklinkSource
    {
        $domain = 'veri-'.$brand->id.'-'.self::quarter();
        if (BacklinkSource::query()->where('brand_id', $brand->id)->where('domain', $domain)->exists()) {
            return null;
        }
        $questions = self::questions($brand);
        if (count($questions) < self::MIN_QUESTIONS) {
            return null;
        }
        $news = LinkSource::query()->where('kind', 'yerel_haber')->where('quality', 'uygun')->whereIn('city', LinkDesk::cities($brand))->orderByDesc('won_count')->limit(5)->pluck('name')->all();

        return BacklinkSource::query()->create([
            'brand_id' => $brand->id, 'name' => 'Veri haberi · '.now('Europe/Istanbul')->year.' '.now('Europe/Istanbul')->quarter.'. çeyrek', 'url' => '', 'domain' => $domain,
            'kind' => 'yerel_haber', 'fee' => 'ucretsiz', 'origin' => 'ai', 'status' => BacklinkSource::NONE, 'engine' => 'veri', 'lane' => 'masa',
            'anchor' => $brand->name, 'material_status' => 'queued',
            'reason' => mb_substr('Son '.self::DAYS.' günde en çok sorulan '.count($questions).' soru: '.implode(' · ', array_slice($questions, 0, 3)).' …'
                .($news !== [] ? ' Gönderilecek: '.implode(', ', $news).'.' : ' Şehrin yerel haber sitelerine gönderilir.'), 0, 240),
        ]);
    }
}
