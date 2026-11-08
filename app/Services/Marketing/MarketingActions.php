<?php

namespace App\Services\Marketing;

use Carbon\CarbonImmutable;

/**
 * "Bu ay pazarlamada ne yapmalıyım" (Pazarlama, 2026-11-08): the few things the month's numbers ask for, read by rules
 * from what each channel cost and brought. Each line says what is wrong in numbers and where it is fixed; nothing is
 * written anywhere from here. At most six, the ones that cost money or hide the truth first.
 */
final class MarketingActions
{
    /**
     * @param  array<string, mixed>  $funnel  MarketingFunnel::funnel()
     * @param  array{expected: ?int, day: int, days: int}  $pace
     * @param  array<string, mixed>  $meta  OwnChannels::meta()
     * @param  array<string, mixed>  $google  OwnChannels::googleAds()
     * @param  array<string, mixed>  $site  OwnChannels::site()
     * @param  array<string, mixed>  $gbp  OwnChannels::gbp()
     * @param  array<string, mixed>  $social  OwnChannels::social()
     * @return list<array{key: string, tone: string, title: string, body: string, channel: ?string, route: ?array{0: string, 1: array<string, string>}, cta: string}>
     */
    public function build(array $funnel, array $pace, array $meta, array $google, array $site, array $gbp, array $social, int $suggested, bool $isThisMonth): array
    {
        $rows = collect($funnel['rows'])->keyBy('key');
        $n = fn (int|float $v): string => number_format((int) round($v), 0, ',', '.');
        $out = [];

        $unknown = (int) ($rows['unknown']['applications'] ?? 0);
        $applications = max(1, (int) $funnel['totals']['applications']);
        if ($unknown > 0) {
            $out[] = ['key' => 'unknown', 'tone' => $unknown * 100 / $applications >= 30 ? 'critical' : 'warning',
                'title' => $n($unknown).' kaydın kaynağı bilinmiyor',
                'body' => $n($applications).' başvurudan '.$n($unknown).' tanesi hiçbir kanala yazılmadı (%'.(int) round($unknown * 100 / $applications).'); hangi kanalın çalıştığı bu kadar eksik görünüyor.'.($suggested > 0 ? ' '.$n($suggested).' kayıt için ilk mesajdan öneri hazır.' : ''),
                'channel' => 'unknown', 'route' => null, 'cta' => 'Kaynakları işaretle'];
        }

        $metaApps = (int) ($rows['meta']['applications'] ?? 0);
        $metaResults = (int) $meta['messages'] + (int) $meta['leads'];
        if ($meta['connected'] && $meta['spend'] > 0 && $metaApps === 0) {
            $out[] = ['key' => 'meta-nothing', 'tone' => 'critical', 'title' => 'Meta\'ya '.$n($meta['spend']).' ₺ gitti, kayda düşen başvuru yok',
                'body' => 'Meta '.$n($metaResults).' mesaj/form sayıyor; satış kayıtlarında "Instagram / Facebook reklamı" kaynaklı başvuru 0. Mesajlar kayda bağlanmıyor ya da kaynakları işaretlenmedi.',
                'channel' => 'meta', 'route' => null, 'cta' => 'Meta\'ya bak'];
        } elseif ($meta['connected'] && $metaResults >= 4 && $metaApps * 2 < $metaResults) {
            $out[] = ['key' => 'meta-gap', 'tone' => 'warning', 'title' => 'Meta '.$n($metaResults).' sonuç sayıyor, kayıtta '.$n($metaApps).' başvuru var',
                'body' => 'Reklamdan gelen mesajların yarısından fazlası satış kaydına reklam kaynaklı olarak düşmedi.', 'channel' => 'meta', 'route' => null, 'cta' => 'Meta\'ya bak'];
        }
        if ($meta['connected'] && $isThisMonth && $meta['last_day'] !== null && CarbonImmutable::parse($meta['last_day'])->lt(CarbonImmutable::now(OwnChannels::TZ)->subDays(4)->startOfDay())
            && ($rows['meta']['target'] ?? 0) > 0) {
            $out[] = ['key' => 'meta-stale', 'tone' => 'warning', 'title' => 'Meta verisi '.CarbonImmutable::parse($meta['last_day'])->locale('tr')->isoFormat('D MMMM').'\'dan beri gelmiyor',
                'body' => 'Reklamlar durdu ya da bağlantının izni yenilenmeli; hedefin '.$n($rows['meta']['target']).' başvurusu bu kanalda.', 'channel' => null,
                'route' => ['operator.integrations.meta', []], 'cta' => 'Meta bağlantısı'];
        }

        $googleTarget = (int) ($rows['google_ads']['target'] ?? 0);
        if ($googleTarget > 0 && (! $google['connected'] || $google['cost'] <= 0)) {
            $out[] = ['key' => 'google-off', 'tone' => 'warning', 'title' => 'Google Ads hedefi '.$n($googleTarget).', bu ay harcama yok',
                'body' => $google['connected'] ? 'Kampanya duruyor; hedef ya kanaldan düşmeli ya da kampanya açılmalı.' : 'Moximu\'nun Google Ads hesabı bağlı değil ya da hiç veri gelmedi.',
                'channel' => 'google_ads', 'route' => null, 'cta' => 'Google Ads\'e bak'];
        }

        if ($social['drafts_week'] > 0) {
            $out[] = ['key' => 'social-approve', 'tone' => 'warning', 'title' => $n($social['drafts_week']).' gönderi bu hafta onay bekliyor',
                'body' => 'Onaylanmayan gönderi saatinde yayınlanmaz.'.($social['accounts'] === 0 ? ' Hesaplar da bağlı değil; onaylansa bile paylaşılamaz.' : ''),
                'channel' => null, 'route' => ['operator.social', []], 'cta' => 'Gönderi planı'];
        }
        if ($social['accounts'] === 0) {
            $out[] = ['key' => 'social-accounts', 'tone' => 'warning', 'title' => 'Instagram, Facebook ve LinkedIn bağlı değil',
                'body' => 'Plan hazır ama hiçbir hesap bağlı olmadığı için hiçbir gönderi yayınlanamıyor.', 'channel' => null,
                'route' => ['operator.social', ['sekme' => 'hesaplar']], 'cta' => 'Hesapları bağla'];
        }

        if ($site['connected'] && $site['clicks_prev'] >= 10 && $site['clicks'] < $site['clicks_prev'] * 0.75) {
            $out[] = ['key' => 'site-down', 'tone' => 'warning', 'title' => 'Google tıklaması geçen aya göre %'.(int) round(($site['clicks_prev'] - $site['clicks']) * 100 / $site['clicks_prev']).' düştü',
                'body' => 'Aynı günler: '.$n($site['clicks']).' tık, geçen ay '.$n($site['clicks_prev']).'. Hangi aramaların tıklandığı kanalın ayrıntısında.', 'channel' => 'web', 'route' => null, 'cta' => 'Aramalara bak'];
        }

        if ($pace['expected'] !== null && $pace['expected'] >= 3 && $funnel['totals']['counted'] < $pace['expected'] * 0.6) {
            $out[] = ['key' => 'pace', 'tone' => 'info', 'title' => 'Tempo geride: bugüne kadar '.$n($pace['expected']).' başvuru beklenirdi, '.$n($funnel['totals']['counted']).' geldi',
                'body' => 'Ayın '.$pace['day'].'. günü; hedef '.$n($funnel['totals']['target']).'. En büyük açık '.$this->biggestGap($funnel).'.', 'channel' => null, 'route' => null, 'cta' => ''];
        }

        $order = ['critical' => 0, 'warning' => 1, 'info' => 2];
        usort($out, fn (array $a, array $b): int => $order[$a['tone']] <=> $order[$b['tone']]);

        return array_slice($out, 0, 6);
    }

    /** @param  array<string, mixed>  $funnel */
    private function biggestGap(array $funnel): string
    {
        $gaps = [];
        foreach ($funnel['rows'] as $row) {
            if ($row['target'] > 0) {
                $gaps[$row['label']] = $row['target'] - $row['applications'];
            }
        }
        arsort($gaps);

        return $gaps === [] ? '—' : (string) array_key_first($gaps).' ('.array_values($gaps)[0].' eksik)';
    }
}
