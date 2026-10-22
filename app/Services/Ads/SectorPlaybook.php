<?php

namespace App\Services\Ads;

use App\Services\Meta\MetaDesk;
use Illuminate\Support\Facades\Cache;

/**
 * Sektör kıyası ve oyun kitabı (Aşama 10, 2026-10-22). For every market a brand races in (city × service, with at
 * least one other brand of the system there), each channel where another brand leads gives one line "sen / sektör
 * ortası / lider" and the leader's way of winning as a step to take (the playbook), rule-built from the same numbers as
 * Kazananlar. Other brands are never named: no brand, campaign or page name of another brand reaches the line.
 */
final class SectorPlaybook
{
    private const string CACHE_KEY = 'sector-playbook:v1';

    public function __construct(private readonly Winners $winners) {}

    /**
     * Brand id => where it is behind, biggest gap first.
     *
     * @return array<int, list<array<string, mixed>>>
     */
    public function all(): array
    {
        if (! Winners::ready()) {
            return [];
        }

        return Cache::remember(self::CACHE_KEY, now()->addHour(), fn (): array => $this->build());
    }

    /** @return list<array<string, mixed>> */
    public function forBrand(int $brandId): array
    {
        return $this->all()[$brandId] ?? [];
    }

    public static function forget(): void
    {
        Cache::forget(self::CACHE_KEY);
    }

    /** @return array<int, list<array<string, mixed>>> */
    private function build(): array
    {
        $raw = $this->winners->raw();
        $out = [];
        $serviceIds = [];
        foreach ($this->winners->marketBrands($raw) as $city => $services) {
            $data = null;
            foreach ($services as $sid => $racing) {
                if (count($racing) < 2) {
                    continue;
                }
                $data ??= $this->winners->load((string) $city, $raw);
                $race = $this->winners->race($data, (int) $sid);
                foreach ($race['channels'] as $channel => $c) {
                    $entries = $c['entries'];
                    if (count($entries) < 2) {
                        continue;
                    }
                    $values = array_map(fn (array $e): float => self::value($channel, $e), $entries);
                    $median = AdServiceStats::median($values);
                    $leader = $entries[0];
                    foreach ($entries as $i => $e) {
                        if ($i === 0) {
                            continue;
                        }
                        $mine = self::value($channel, $e);
                        $lead = self::value($channel, $leader);
                        $serviceIds[(int) $sid] = true;
                        $out[(int) $e['brand_id']][] = ['city' => (string) $city, 'service_id' => (int) $sid, 'channel' => $channel, 'label' => (string) $c['label'],
                            'type' => $c['type'], 'mine' => $mine, 'median' => $median, 'leader' => $lead, 'of' => count($entries), 'rank' => $i + 1,
                            'gap' => self::gap($channel, $mine, $lead), 'play' => $this->play($channel, $leader, (string) $c['type'], (int) $sid)];
                    }
                }
            }
        }
        $names = MetaDesk::serviceNames(array_keys($serviceIds));
        foreach ($out as $brandId => $rows) {
            $rows = array_map(function (array $r) use ($names): array {
                $r['service'] = (string) ($names[$r['service_id']] ?? 'Hizmet');
                $r['line'] = $r['service'].' · '.$r['city'].' · '.$r['label'].': '.self::compare($r['channel'], $r['mine'], $r['median'], $r['leader'], $r['type']);
                unset($r['type']);

                return $r;
            }, $rows);
            usort($rows, fn (array $a, array $b): int => $b['gap'] <=> $a['gap']);
            $out[$brandId] = $rows;
        }

        return $out;
    }

    /** The number a channel is judged on: cost per result, average position or rating. */
    private static function value(string $channel, array $e): float
    {
        return (float) match ($channel) {
            'web' => $e['position'],
            'gbp' => $e['rating'],
            default => $e['cpr'],
        };
    }

    /** How far behind the leader, as a share (0.4 = 40% worse). */
    private static function gap(string $channel, float $mine, float $leader): float
    {
        return match ($channel) {
            'gbp' => $leader > 0 ? max(0.0, ($leader - $mine) / $leader) : 0.0,
            default => $leader > 0 ? max(0.0, ($mine - $leader) / $leader) : 0.0,
        };
    }

    /** "sen 150 / ortası 125 / lider 100 TRY dönüşüm başı" */
    public static function compare(string $channel, float $mine, float $median, float $leader, ?string $type): string
    {
        $num = fn (float $v, int $d = 0): string => number_format($v, $d, ',', '.');

        return match ($channel) {
            'web' => 'sen '.$num($mine, 1).'. sıra / ortası '.$num($median, 1).' / lider '.$num($leader, 1),
            'gbp' => 'sen '.$num($mine, 1).' puan / ortası '.$num($median, 1).' / lider '.$num($leader, 1),
            default => 'sen '.$num($mine).' / ortası '.$num($median).' / lider '.$num($leader).' TRY '.(AdServiceStats::TYPE_LABELS[$type ?? ''] ?? 'sonuç').' başı',
        };
    }

    /** The leader's way of winning, as the brand's next step. No name of the leader, its campaign or its page. */
    private function play(string $channel, array $e, string $type, int $serviceId): string
    {
        $num = fn (float $v): string => number_format($v, 0, ',', '.');
        $label = AdServiceStats::TYPE_LABELS[$type] ?? 'sonuç';

        return match ($channel) {
            'web' => 'Lider bu hizmete '.(int) $e['pages'].' sayfa bağlamış, en iyi sayfası 30 günde '.$num((float) ($e['top_clicks'] ?? 0)).' tık alıyor. Hizmet sayfanı güçlendir, sık sorulanları ayrı yazılarla yanıtla.',
            'google_ads' => 'Lider 30 günde '.$num((float) $e['results']).' dönüşümü, dönüşüm başı '.$num((float) $e['cpr']).' TRY\'ye alıyor. Bu hizmete ayrı reklam grubu aç, reklamı hizmet sayfasına bağla, arama terimlerini haftalık temizle.',
            'meta' => 'Lider 30 günde '.$num((float) $e['results']).' '.$label.', '.$label.' başı '.$num((float) $e['cpr']).' TRY. '.$this->metaWay((int) $e['brand_id'], $serviceId, $type),
            'gbp' => 'Lider '.number_format((float) $e['rating'], 1, ',', '.').' puan, '.(int) $e['reviews'].' yorum, son 30 günde '.(int) $e['new_reviews'].' yeni yorum. Memnun hastaya yorum bağlantısını gönder, hizmeti profilde listele.',
            default => '',
        };
    }

    /** The leader's Meta setup (objective, destination, audience, best ad format) without its campaign name. */
    private function metaWay(int $brandId, int $serviceId, string $type): string
    {
        $text = $this->winners->metaWay($brandId, $serviceId, $type);

        return $text !== '' ? 'Kurgusu: '.$text.'.' : 'Anında form ve Advantage+ kitleyle başla, en iyi reklamı çoğalt.';
    }
}
