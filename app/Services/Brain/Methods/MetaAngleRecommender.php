<?php

namespace App\Services\Brain\Methods;

use App\Services\Brain\Proposals\Kinds\MetaAdServicesKind;
use App\Services\Brain\RecommendationWriter;
use Illuminate\Support\Facades\DB;

/**
 * Meta: which message angle brings results most cheaply for a service across the portfolio. An angle becomes a
 * method (hypothesis) when at least MIN_BRANDS brands ran it with results and its median cost per result is at least
 * MIN_ADVANTAGE cheaper than the service's overall median; brands advertising the service on Meta without that angle
 * get a recommendation to test it. Counts only, no other brand named.
 */
final class MetaAngleRecommender
{
    private const int MIN_BRANDS = 3;

    private const float MIN_ADVANTAGE = 0.2;

    public function __construct(private readonly RecommendationWriter $writer) {}

    /** @return int recommendations added */
    public function run(): int
    {
        $rows = DB::table('brain_meta_ads')->whereNotNull('service_id')->whereNotNull('angle')->where('spend', '>', 0)
            ->groupBy('service_id', 'brand_id', 'angle')->selectRaw('service_id, brand_id, angle, sum(spend) as spend, sum(results) as results')->get();
        $added = 0;
        $items = [];
        foreach ($rows->groupBy('service_id') as $serviceId => $serviceRows) {
            $cpr = fn ($r): ?float => (float) $r->results > 0 ? (float) $r->spend / (float) $r->results : null;
            $all = $serviceRows->map($cpr)->filter()->sort()->values();
            if ($all->count() < self::MIN_BRANDS) {
                continue;
            }
            $overall = (float) $all->median();
            foreach ($serviceRows->groupBy('angle') as $angle => $angleRows) {
                $values = $angleRows->map($cpr)->filter()->values();
                if ($values->count() < self::MIN_BRANDS || (float) $values->median() > $overall * (1 - self::MIN_ADVANTAGE)) {
                    continue;
                }
                $label = MetaAdServicesKind::ANGLE_LABELS[$angle] ?? $angle;
                $evidence = ['brands' => $values->count(), 'median_cpr' => round((float) $values->median(), 2), 'service_median_cpr' => round($overall, 2)];
                DB::table('brain_methods')->updateOrInsert(['service_id' => $serviceId, 'page_type' => null, 'channel' => 'meta_ads', 'feature' => 'angle:'.$angle],
                    ['label' => 'Meta mesaj açısı: '.$label, 'evidence' => json_encode($evidence), 'updated_at' => now(), 'created_at' => now(), 'discovered_at' => now()]);
                $method = DB::table('brain_methods')->where('service_id', $serviceId)->where('channel', 'meta_ads')->where('feature', 'angle:'.$angle)->first();
                if ($method === null || $method->status === 'retired') {
                    continue;
                }
                $using = $angleRows->pluck('brand_id')->all();
                foreach ($serviceRows->pluck('brand_id')->unique()->diff($using) as $brandId) {
                    $items[(int) $brandId][] = ['key' => $serviceId.'|'.$angle, 'channel' => 'meta_ads', 'type' => 'meta_try_angle', 'service_id' => (int) $serviceId,
                        'brand_id' => (int) $brandId, 'method_id' => (int) $method->id, 'basis' => $method->status === 'validated' ? 'validated' : 'observational',
                        'title' => 'Meta\'da "'.$label.'" mesajlı bir kreatif test edin',
                        'detail' => sprintf('Bu hizmette %d markada bu mesaj açısı sonuç başı maliyeti hizmet ortalamasından belirgin düşük getirdi (medyan %s, hizmet medyanı %s). Markanızın bu hizmet reklamlarında bu açı yok.',
                            $evidence['brands'], number_format($evidence['median_cpr'], 2, ',', '.'), number_format($evidence['service_median_cpr'], 2, ',', '.')),
                        'evidence' => $evidence + ['angle' => $angle]];
                }
            }
        }
        $brands = DB::table('brain_meta_ads')->whereNotNull('brand_id')->distinct()->pluck('brand_id');
        foreach ($brands as $brandId) {
            $added += $this->writer->sync(['source' => 'meta_angles', 'brand_id' => (int) $brandId], $items[(int) $brandId] ?? [])['added'];
        }

        return $added;
    }
}
