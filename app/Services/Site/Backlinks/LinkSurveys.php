<?php

namespace App\Services\Site\Backlinks;

use App\Models\BacklinkSource;
use App\Models\Brand;
use App\Models\BrandEpicrisisItem;
use App\Models\LinkSurvey;
use App\Services\Epicrisis\EpicrisisStore;
use App\Services\Site\SiteDomains;
use Illuminate\Support\Str;
use Throwable;

/**
 * İlişki linkleri (Backlink hizmeti mimarisi, motor 3): the client's "Bağlantı kaynakları" form. Each answered line
 * (a membership, a supplier, a university, a sponsorship, a press page, a partner) is written to the brand's epikriz as
 * the client's own word and becomes a candidate row on the Bağlantı masası (engine ilişki, or anılma for press), which
 * the desk takes in at its next fill with its material. A line seen before is not added twice.
 */
final class LinkSurveys
{
    public const int MAX_LINES = 12;

    public function __construct(private readonly EpicrisisStore $epicrisis) {}

    public function for(Brand $brand): LinkSurvey
    {
        return LinkSurvey::query()->firstOrCreate(['brand_id' => $brand->id], ['token' => Str::random(48), 'answers' => []]);
    }

    public static function url(LinkSurvey $survey): string
    {
        return route('link-survey', ['token' => $survey->token]);
    }

    /**
     * @param  array<string, mixed>  $answers  question key => text (one item per line)
     * @return int rows added to the desk
     */
    public function answer(LinkSurvey $survey, array $answers, string $note = ''): int
    {
        $brand = $survey->brand;
        $clean = [];
        foreach (LinkSurvey::QUESTIONS as $key => $question) {
            $lines = array_values(array_unique(array_filter(array_map(fn (string $l): string => mb_substr(trim($l), 0, 300),
                preg_split('/\R/u', (string) ($answers[$key] ?? '')) ?: []))));
            $clean[$key] = implode("\n", array_slice($lines, 0, self::MAX_LINES));
        }
        $survey->forceFill(['answers' => $clean, 'note' => mb_substr(trim($note), 0, 2000) ?: null, 'answered_at' => now()])->save();
        if (! $brand instanceof Brand) {
            return 0;
        }
        $added = 0;
        $known = BacklinkSource::query()->where('brand_id', $brand->id)->pluck('domain')->flip();
        foreach (LinkSurvey::QUESTIONS as $key => [$question, , $field, $kind, $engine]) {
            foreach (array_filter(explode("\n", $clean[$key])) as $line) {
                try {
                    $this->epicrisis->put($brand, BrandEpicrisisItem::FACT, $field, $line, null, 'customer');
                } catch (Throwable) {
                    // the desk row below matters more than the fact line
                }
                $url = preg_match('#https?://[^\s,]+#iu', $line, $m) === 1 ? rtrim($m[0], '.);') : '';
                $host = $url !== '' ? SiteDomains::host($url) : null;
                $domain = $host ?? 'iliski-'.substr(hash('sha256', $key.'|'.mb_strtolower($line)), 0, 12);
                if ($known->has($domain)) {
                    continue;
                }
                $name = trim((string) preg_replace('#https?://\S+#iu', '', $line)) ?: ($host ?? $line);
                BacklinkSource::query()->create([
                    'brand_id' => $brand->id, 'name' => mb_substr($name, 0, 200), 'url' => $url, 'domain' => $domain, 'kind' => $kind, 'fee' => 'teyit',
                    'origin' => 'musteri', 'status' => BacklinkSource::NONE, 'engine' => $engine, 'lane' => $engine === 'anilma' && $url !== '' ? 'masa' : 'sen',
                    'reason' => mb_substr('Müşteri formu: '.$question, 0, 240),
                ]);
                $known[$domain] = true;
                $added++;
            }
        }
        $survey->forceFill(['rows_created' => (int) $survey->rows_created + $added])->save();

        return $added;
    }
}
