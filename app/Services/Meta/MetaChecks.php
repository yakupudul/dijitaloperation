<?php

namespace App\Services\Meta;

use App\Models\BrandServiceArea;
use App\Models\DigitalAsset;
use App\Models\Page;
use App\Services\SeoTasks\SeoText;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;

/**
 * The Meta system checks (no AI), from the collected data of the bound account over the last 28 days (vs the 28
 * before). Each check ends as `issue` (→ one suggestion with its evidence rows), `ok`, `no_data` ("veri yok") or
 * `low_data` ("veri az": never a pause / "kapat" proposal on little data). Run daily and on "Yeniden kontrol et";
 * the states are kept for the screen.
 */
final class MetaChecks
{
    public const array CHECKS = [
        'pixel' => 'Pixel / CAPI',
        'delivery' => 'Yayın sorunları',
        'objective' => 'Hedef ↔ optimizasyon olayı',
        'utm' => 'UTM parametreleri',
        'landing' => 'Açılış sayfası',
        'region' => 'Bölge ve dil uyumu',
        'service' => 'Hizmet uyumu',
        'change' => 'Harcama / sonuç değişimi',
        'fatigue' => 'Kreatif yorgunluğu',
        'weak_ad' => 'Sonuç getirmeyen reklam',
        'ad_count' => 'Reklam sayısı ↔ bütçe',
        'starved' => 'Bütçe almayan reklam',
    ];

    /** Objectives that buy results (leads / sales). */
    private const array RESULT_OBJECTIVES = ['OUTCOME_LEADS', 'LEAD_GENERATION', 'OUTCOME_SALES', 'CONVERSIONS'];

    /** Optimization events that are not results. */
    private const array NON_RESULT_GOALS = ['LINK_CLICKS', 'LANDING_PAGE_VIEWS', 'IMPRESSIONS', 'REACH', 'POST_ENGAGEMENT', 'THRUPLAY', 'AD_RECALL_LIFT', 'PROFILE_VISIT'];

    private const array DELIVERY_ISSUES = ['DISAPPROVED' => 'reddedildi', 'WITH_ISSUES' => 'sorunlu', 'PENDING_BILLING_INFO' => 'ödeme bilgisi bekliyor', 'ACCOUNT_DISABLED' => 'hesap kapalı'];

    /** Meta ad targeting locale ids (numeric) => ISO language; ids outside the map are unknown ("veri yok"). */
    public const array LOCALES = [
        6 => 'en', 24 => 'en', 1001 => 'en', 19 => 'tr', 5 => 'de', 28 => 'ar', 17 => 'ru', 9 => 'fr', 44 => 'fr', 1012 => 'fr',
    ];

    /** Change check: enough data = at least this many results in the previous window. */
    public const int MIN_PREVIOUS_RESULTS = 10;

    /** Weak ad check: the account needs at least this many results before an ad is proposed to be paused. */
    public const int MIN_ACCOUNT_RESULTS = 5;

    public function __construct(private readonly MetaScreen $screen, private readonly MetaSuggestions $suggestions) {}

    /**
     * Runs the checks, stores issues as suggestions and keeps the states for the screen.
     *
     * @return list<array{id: string, label: string, state: string, detail: string}>
     */
    public function sync(DigitalAsset $asset): array
    {
        $asset->loadMissing('brand');
        $account = $this->screen->account($asset);
        $states = [];
        $items = [];
        if ($account !== null) {
            $ctx = $this->context($asset, $account);
            foreach (array_keys(self::CHECKS) as $id) {
                $result = $this->{'check'.str_replace('_', '', ucwords($id, '_'))}($ctx);
                $states[] = ['id' => $id, 'label' => self::CHECKS[$id], 'state' => $result['state'], 'detail' => $result['detail']];
                if ($result['state'] === 'issue') {
                    $items[] = ['key' => $id, 'title' => $result['title'], 'reason' => $result['detail'], 'priority' => $result['priority'],
                        'evidence' => array_slice($result['rows'], 0, 10), 'action_type' => 'meta_check',
                        'action' => ['check' => $id, 'text' => $result['todo']."\n".implode("\n", array_map(fn (array $row): string => '- '.implode(' · ', array_map('strval', $row)), array_slice($result['rows'], 0, 10)))]];
                }
            }
        } else {
            foreach (self::CHECKS as $id => $label) {
                $states[] = ['id' => $id, 'label' => $label, 'state' => 'no_data', 'detail' => 'Reklam hesabı bağlı değil.'];
            }
        }
        $this->suggestions->replaceGroup($asset, 'check', $items);
        Cache::put(self::stateKey((int) $asset->id), ['at' => now()->toIso8601String(), 'checks' => $states], now()->addDays(3));

        return $states;
    }

    /** @return array{at: string, checks: list<array{id: string, label: string, state: string, detail: string}>}|null */
    public static function states(int $assetId): ?array
    {
        $value = Cache::get(self::stateKey($assetId));

        return is_array($value) ? $value : null;
    }

    public static function stateKey(int $assetId): string
    {
        return 'meta-checks:'.$assetId;
    }

    /** @return array<string, mixed> */
    private function context(DigitalAsset $asset, array $account): array
    {
        $w = $this->screen->window($account, 28);
        $entities = $this->screen->entities($account);
        $ads = $this->screen->adPerformance($account, $w['from'], $w['to'], $entities);
        $prev = $this->screen->adPerformance($account, $w['prev_from'], $w['prev_to'], $entities);
        $recent = $this->screen->adPerformance($account, CarbonImmutable::parse($w['to'])->subDays(2)->toDateString(), $w['to'], $entities);
        $last14 = $this->screen->adPerformance($account, CarbonImmutable::parse($w['to'])->subDays(13)->toDateString(), $w['to'], $entities);
        $week = $this->screen->adPerformance($account, CarbonImmutable::parse($w['to'])->subDays(6)->toDateString(), $w['to'], $entities);

        return ['asset' => $asset, 'account' => $account, 'w' => $w, 'e' => $entities, 'ads' => $ads, 'prev' => $prev, 'recent' => $recent, 'last14' => $last14, 'week' => $week,
            'cur_total' => MetaScreen::totals($ads), 'prev_total' => MetaScreen::totals($prev), 'money' => fn (?float $v): string => $v === null ? '—' : number_format($v, 2, ',', '.').' '.$account['currency']];
    }

    /** @return array{state: string, detail: string, title?: string, priority?: int, rows?: list<array<string, mixed>>, todo?: string} */
    private function checkPixel(array $ctx): array
    {
        $pixel = $this->screen->pixel($ctx['account']);
        if ($pixel['state'] === 'no_data') {
            return ['state' => 'no_data', 'detail' => 'Pixel verisi yok · CAPI: veri yok'];
        }
        $websiteAds = collect($this->activeCreatives($ctx))->contains(fn (array $c): bool => $c['link_url'] !== '' && $c['lead_gen_form_id'] === '');
        if ($pixel['state'] === 'missing') {
            return $websiteAds
                ? $this->issue('Pixel bağlı değil', 'Siteye giden reklamlar var; hesapta pixel yok.', 1, [['durum' => 'pixel yok', 'capi' => 'veri yok']], 'Events Manager’da pixel kurun ve reklam hesabına bağlayın; site olaylarını (lead, form) test edin.')
                : ['state' => 'ok', 'detail' => 'Siteye giden reklam yok; pixel gerekmiyor.'];
        }
        if ($pixel['state'] === 'silent') {
            return $this->issue('Pixel olay göndermiyor', 'Son olay: '.($pixel['last_fired'] ?? 'hiç').' · CAPI: veri yok.', 1,
                array_map(fn (array $p): array => ['pixel' => $p['name'], 'son_olay' => $p['last_fired'] ?? 'yok', 'durum' => $p['unavailable'] ? 'kullanılamıyor' : 'etkin'], $pixel['pixels']),
                'Events Manager’da pixel’in olay aldığını test edin (Test Events); site etiketini ve CAPI bağlantısını kontrol edin.');
        }

        return ['state' => 'ok', 'detail' => 'Son olay: '.$pixel['last_fired'].' · CAPI: veri yok'];
    }

    private function checkDelivery(array $ctx): array
    {
        $e = $ctx['e'];
        if ($e['ads'] === [] && $e['campaigns'] === []) {
            return ['state' => 'no_data', 'detail' => 'Kampanya / reklam verisi yok.'];
        }
        $rows = [];
        foreach ($e['ads'] as $ad) {
            if (isset(self::DELIVERY_ISSUES[$ad['status']])) {
                $rows[] = ['reklam' => $ad['name'], 'durum' => self::DELIVERY_ISSUES[$ad['status']]];
            }
        }
        $recent = MetaScreen::rollup($ctx['recent'], 'campaign_id');
        $current = MetaScreen::rollup($ctx['ads'], 'campaign_id');
        foreach ($e['campaigns'] as $id => $c) {
            if ($c['status'] === 'ACTIVE' && ($current[$id]['spend'] ?? 0) > 0 && ($recent[$id]['impressions'] ?? 0) === 0) {
                $rows[] = ['kampanya' => $c['name'], 'durum' => 'son 3 gün gösterim yok'];
            }
        }
        if ($rows === []) {
            return ['state' => 'ok', 'detail' => 'Reddedilen / durmuş reklam yok.'];
        }

        return $this->issue('Yayın sorunu: '.count($rows).' kayıt', count($rows).' reklam / kampanya yayında değil ya da reddedildi.', 1, $rows,
            'Reklam Yöneticisi’nde reddedilen reklamların nedenini okuyun ve düzeltin; gösterim almayan kampanyanın bütçe / takvim / hedef kitlesini kontrol edin.');
    }

    private function checkObjective(array $ctx): array
    {
        $e = $ctx['e'];
        $spend = MetaScreen::rollup($ctx['ads'], 'adset_id');
        $rows = [];
        $known = 0;
        foreach ($e['adsets'] as $id => $adset) {
            if (($spend[$id]['spend'] ?? 0) <= 0 || $adset['optimization_goal'] === '') {
                continue;
            }
            $known++;
            $objective = (string) ($e['campaigns'][$adset['campaign_id']]['objective'] ?? '');
            if (in_array($objective, self::RESULT_OBJECTIVES, true) && in_array($adset['optimization_goal'], self::NON_RESULT_GOALS, true)) {
                $rows[] = ['reklam_seti' => $adset['name'], 'hedef' => MetaScreen::objectiveLabel($objective), 'optimizasyon' => $adset['optimization_goal'], 'harcama' => ($ctx['money'])($spend[$id]['spend'])];
            }
        }
        if ($known === 0) {
            return ['state' => 'no_data', 'detail' => 'Harcaması olan reklam setinin optimizasyon verisi yok.'];
        }
        if ($rows === []) {
            return ['state' => 'ok', 'detail' => $known.' reklam seti hedefiyle uyumlu olaya optimize ediliyor.'];
        }

        return $this->issue('Optimizasyon olayı sonuç değil', count($rows).' reklam seti sonuç hedefli kampanyada tıklama / gösterime optimize ediliyor.', 2, $rows,
            'Bu reklam setlerinin optimizasyon olayını potansiyel müşteri / dönüşüm olayına çevirin.');
    }

    private function checkUtm(array $ctx): array
    {
        $links = array_filter($this->activeCreatives($ctx), fn (array $c): bool => str_starts_with($c['link_url'], 'http'));
        if ($links === []) {
            return ['state' => 'no_data', 'detail' => 'Siteye giden reklam bağlantısı yok.'];
        }
        $rows = [];
        foreach ($links as $c) {
            if (! str_contains(strtolower($c['link_url']), 'utm_source=')) {
                $rows[$c['link_url']] = ['reklam' => $c['ad'], 'bağlantı' => mb_substr($c['link_url'], 0, 120)];
            }
        }
        if ($rows === []) {
            return ['state' => 'ok', 'detail' => count($links).' bağlantının hepsinde UTM var.'];
        }

        return $this->issue('UTM eksik: '.count($rows).' bağlantı', count($rows).' / '.count($links).' reklam bağlantısında utm_source yok; GA4’te Meta ayrı görünmez.', 3, array_values($rows),
            'Reklam bağlantılarına URL parametresi ekleyin: utm_source=facebook&utm_medium=paid_social&utm_campaign={{campaign.name}}');
    }

    private function checkLanding(array $ctx): array
    {
        $asset = $ctx['asset'];
        $sites = DigitalAsset::query()->where('brand_id', $asset->brand_id)->where('type', 'website')->get(['id', 'domain', 'primary_url']);
        $links = array_filter($this->activeCreatives($ctx), fn (array $c): bool => str_starts_with($c['link_url'], 'http') && $c['lead_gen_form_id'] === '');
        if ($sites->isEmpty() || $links === []) {
            return ['state' => 'no_data', 'detail' => $sites->isEmpty() ? 'Markanın sitesi bağlı değil.' : 'Siteye giden reklam bağlantısı yok.'];
        }
        $hosts = $sites->map(fn (DigitalAsset $s): string => self::host((string) ($s->domain ?: $s->primary_url)))->filter()->all();
        $pages = Page::query()->whereIn('website_asset_id', $sites->pluck('id'))->pluck('url')->map(fn ($u): string => self::normalizeUrl((string) $u))->flip();
        $rows = [];
        foreach ($links as $c) {
            $host = self::host($c['link_url']);
            if (! in_array($host, $hosts, true)) {
                $rows[$c['link_url']] = ['reklam' => $c['ad'], 'bağlantı' => mb_substr($c['link_url'], 0, 120), 'sorun' => 'farklı alan adı'];
            } elseif ($pages->isNotEmpty() && ! $pages->has(self::normalizeUrl($c['link_url']))) {
                $rows[$c['link_url']] = ['reklam' => $c['ad'], 'bağlantı' => mb_substr($c['link_url'], 0, 120), 'sorun' => 'sitede sayfa yok'];
            }
        }
        if ($rows === []) {
            return ['state' => 'ok', 'detail' => count($links).' bağlantı markanın sitesindeki sayfalara gidiyor.'];
        }

        return $this->issue('Açılış sayfası uyumsuz: '.count($rows), count($rows).' reklam markanın sitesinde olmayan bir adrese gidiyor.', 2, array_values($rows),
            'Bu reklamların bağlantısını markanın sitesindeki ilgili hizmet sayfasına çevirin.');
    }

    /** Region (service areas / branch cities) and language (ad set locales vs the brand's languages) of spending ad sets. */
    private function checkRegion(array $ctx): array
    {
        $region = $this->regionFit($ctx);
        $language = $this->languageFit($ctx);
        if ($region['state'] === 'issue' || $language['state'] === 'issue') {
            $rows = [...($region['rows'] ?? []), ...($language['rows'] ?? [])];
            $title = $region['state'] === 'issue' && $language['state'] === 'issue' ? 'Bölge ve dil uyumsuz: '.count($rows)
                : ($region['state'] === 'issue' ? $region['title'] : $language['title']);
            $detail = implode(' ', array_filter([$region['state'] === 'issue' ? $region['detail'] : null, $language['state'] === 'issue' ? $language['detail'] : null]));

            return $this->issue($title, $detail, 2, $rows, trim(($region['todo'] ?? '').' '.($language['todo'] ?? '')));
        }
        if ($region['state'] === 'no_data' && $language['state'] === 'no_data') {
            return ['state' => 'no_data', 'detail' => $region['detail'].' '.$language['detail']];
        }

        return ['state' => 'ok', 'detail' => $region['detail'].' '.$language['detail']];
    }

    /** @return array{state: string, detail: string, title?: string, priority?: int, rows?: list<array<string, mixed>>, todo?: string} */
    private function languageFit(array $ctx): array
    {
        $languages = array_values(array_filter(array_map(fn ($l): string => mb_strtolower(substr((string) $l, 0, 2)), (array) ($ctx['asset']->brand?->languages ?? []))));
        if ($languages === []) {
            return ['state' => 'no_data', 'detail' => 'Markanın dili girilmemiş.'];
        }
        $spend = MetaScreen::rollup($ctx['ads'], 'adset_id');
        $known = 0;
        $rows = [];
        foreach ($ctx['e']['adsets'] as $id => $adset) {
            if (($spend[$id]['spend'] ?? 0) <= 0 || ! is_array($adset['targeting'] ?? null) || $adset['targeting'] === []) {
                continue;
            }
            $locales = array_map('intval', (array) ($adset['targeting']['locales'] ?? []));
            if ($locales === []) {
                $known++; // no locale = every language

                continue;
            }
            $codes = array_values(array_unique(array_filter(array_map(fn (int $l): ?string => self::LOCALES[$l] ?? null, $locales))));
            if (count($codes) < count(array_unique($locales)) && array_intersect($codes, $languages) === []) {
                continue; // an unknown locale may be the brand's language: veri yok
            }
            $known++;
            if (array_intersect($codes, $languages) === []) {
                $rows[] = ['reklam_seti' => $adset['name'], 'dil' => implode(', ', $codes), 'marka_dilleri' => implode(', ', $languages), 'sorun' => 'markanın dili hedeflenmiyor'];
            }
        }
        if ($known === 0) {
            return ['state' => 'no_data', 'detail' => 'Dil hedefi verisi yok.'];
        }
        if ($rows === []) {
            return ['state' => 'ok', 'detail' => 'Dil hedefi markanın dilleriyle uyumlu.'];
        }

        return $this->issue('Dil uyumsuz: '.count($rows), count($rows).' reklam seti markanın dillerini hedeflemiyor.', 2, $rows,
            'Reklam setlerinin dil hedefine markanın dillerini ekleyin ya da dil hedefini kaldırın.');
    }

    /** @return array{state: string, detail: string, title?: string, priority?: int, rows?: list<array<string, mixed>>, todo?: string} */
    private function regionFit(array $ctx): array
    {
        $areas = BrandServiceArea::query()->where('brand_id', $ctx['asset']->brand_id)->get();
        if ($areas->isEmpty()) {
            return ['state' => 'no_data', 'detail' => 'Markanın hizmet bölgesi girilmemiş.'];
        }
        $areaNames = $areas->flatMap(fn (BrandServiceArea $a): array => array_filter([$a->city_name, $a->district_name, $a->name]))->map(fn ($n): string => SeoText::fold((string) $n))->filter()->unique()->all();
        $spend = MetaScreen::rollup($ctx['ads'], 'adset_id');
        $targeted = [];
        $rows = [];
        foreach ($ctx['e']['adsets'] as $id => $adset) {
            if (($spend[$id]['spend'] ?? 0) <= 0) {
                continue;
            }
            $geo = (array) ($adset['targeting']['geo_locations'] ?? []);
            $places = collect(array_merge((array) ($geo['cities'] ?? []), (array) ($geo['regions'] ?? [])))->pluck('name')->filter()->map(fn ($n): string => (string) $n)->all();
            if ($places === []) {
                continue;
            }
            $matched = array_filter($places, fn (string $place): bool => collect($areaNames)->contains(fn (string $area): bool => SeoText::containsPhrase($place, $area) || SeoText::containsPhrase($area, $place)));
            array_push($targeted, ...array_map(fn (string $p): string => SeoText::fold($p), $places));
            if ($matched === []) {
                $rows[] = ['reklam_seti' => $adset['name'], 'hedef' => implode(', ', array_slice($places, 0, 5)), 'sorun' => 'hizmet bölgesi dışında'];
            }
        }
        if ($targeted === []) {
            return ['state' => 'no_data', 'detail' => 'Reklam setlerinde şehir / bölge hedefi verisi yok.'];
        }
        foreach ($areas->where('physical_branch', true) as $branch) {
            $city = SeoText::fold((string) ($branch->city_name ?: $branch->name));
            if ($city !== '' && ! collect($targeted)->contains(fn (string $t): bool => SeoText::containsPhrase($t, $city) || SeoText::containsPhrase($city, $t))) {
                $rows[] = ['bölge' => $branch->displayName(), 'sorun' => 'şubenin şehri hedeflenmiyor'];
            }
        }
        if ($rows === []) {
            return ['state' => 'ok', 'detail' => 'Hedeflenen şehirler markanın hizmet bölgeleriyle uyumlu.'];
        }

        return $this->issue('Bölge uyumsuz: '.count($rows), count($rows).' reklam seti / şube hizmet bölgeleriyle uyuşmuyor.', 2, $rows,
            'Reklam setlerinin konum hedefini markanın hizmet bölgelerine göre düzeltin.');
    }

    private function checkService(array $ctx): array
    {
        $brand = $ctx['asset']->brand;
        $offerings = $brand !== null ? $this->screen->offeringIndex($brand) : [];
        if ($offerings === []) {
            return ['state' => 'no_data', 'detail' => 'Markanın onaylı hizmeti yok.'];
        }
        $map = $this->screen->campaignServices($brand, $ctx['e']);
        $spend = MetaScreen::rollup($ctx['ads'], 'campaign_id');
        $served = [];
        $rows = [];
        foreach ($spend as $campaignId => $row) {
            if ($row['spend'] <= 0) {
                continue;
            }
            if (isset($map[$campaignId])) {
                $served[$map[$campaignId]] = true;
            } else {
                $rows[] = ['kampanya' => (string) ($ctx['e']['campaigns'][$campaignId]['name'] ?? $campaignId), 'sorun' => 'hizmete bağlanamadı', 'harcama' => ($ctx['money'])($row['spend'])];
            }
        }
        if ($spend === []) {
            return ['state' => 'no_data', 'detail' => 'Son 28 günde harcama yok.'];
        }
        foreach ($offerings as $offering) {
            if ($offering['priority'] === 'main' && ! isset($served[$offering['name']])) {
                array_unshift($rows, ['hizmet' => $offering['name'], 'sorun' => 'ana hizmet için reklam yok']);
            }
        }
        if ($rows === []) {
            return ['state' => 'ok', 'detail' => 'Ana hizmetlerin hepsinde reklam var.'];
        }

        return $this->issue('Hizmet uyumu: '.count($rows).' kayıt', 'Ana hizmet reklamsız ya da kampanya hiçbir hizmete bağlanamıyor.', 3, $rows,
            'Reklamsız ana hizmet için kampanya açın; bağlanamayan kampanyanın adına / metnine hizmet adını yazın.');
    }

    private function checkChange(array $ctx): array
    {
        [$c, $p] = [$ctx['cur_total'], $ctx['prev_total']];
        if ($p['results'] < self::MIN_PREVIOUS_RESULTS) {
            return ['state' => $p['spend'] > 0 ? 'low_data' : 'no_data', 'detail' => 'Önceki 28 günde '.(int) $p['results'].' sonuç; karşılaştırma için az.'];
        }
        $cprChange = MetaScreen::change($c['cpr'], $p['cpr']);
        $resultsChange = MetaScreen::change($c['results'], $p['results']);
        $spendChange = MetaScreen::change($c['spend'], $p['spend']);
        $row = ['harcama' => ($ctx['money'])($c['spend']).' ('.self::pct($spendChange).')', 'sonuç' => $c['results'].' ('.self::pct($resultsChange).')', 'sonuç_başı' => ($ctx['money'])($c['cpr']).' ('.self::pct($cprChange).')'];
        if (($c['results'] > 0 && $cprChange !== null && $cprChange >= 30) || ($resultsChange !== null && $resultsChange <= -30 && ($spendChange ?? 0) >= -10)) {
            return $this->issue('Sonuç başı maliyet arttı', 'Sonuç '.self::pct($resultsChange).', sonuç başı maliyet '.self::pct($cprChange).' (önceki 28 güne göre).', 2, [$row],
                'Analiz sekmesinde kampanya / reklam seti değişimine bakın; maliyeti artıranı bulun (kitle, kreatif, bütçe değişikliği).');
        }

        return ['state' => 'ok', 'detail' => 'Sonuç '.self::pct($resultsChange).', sonuç başı maliyet '.self::pct($cprChange).'.'];
    }

    private function checkFatigue(array $ctx): array
    {
        $fatigue = $this->screen->fatigue($ctx['account']);
        if ($ctx['ads'] === []) {
            return ['state' => 'no_data', 'detail' => 'Reklam verisi yok.'];
        }
        if ($fatigue === []) {
            return ['state' => 'ok', 'detail' => 'Yorulan kreatif yok.'];
        }
        $rows = [];
        foreach ($fatigue as $adId => $f) {
            $rows[] = ['reklam' => (string) ($ctx['e']['ads'][$adId]['name'] ?? $adId), 'sıklık' => $f['frequency'], 'ctr' => $f['first_ctr'].'% → '.$f['last_ctr'].'%'];
        }

        return $this->issue('Kreatif yorgunluğu: '.count($rows).' reklam', count($rows).' reklamda sıklık yüksek ve CTR %'.max(array_column($fatigue, 'drop')).'’e kadar düştü.', 2, $rows,
            'Bu reklamlar için yeni kreatif hazırlayın (Kreatifler › Kreatif öner) ve eskisini yenisiyle değiştirin.');
    }

    private function checkWeakAd(array $ctx): array
    {
        $total = $ctx['cur_total'];
        if ($total['results'] < self::MIN_ACCOUNT_RESULTS || $total['cpr'] === null) {
            return ['state' => $total['spend'] > 0 ? 'low_data' : 'no_data', 'detail' => 'Hesapta '.(int) $total['results'].' sonuç; kapatma önerisi için veri az.'];
        }
        $rows = [];
        foreach ($ctx['ads'] as $id => $ad) {
            if ($ad['results'] <= 0 && $ad['impressions'] >= 1000 && $ad['spend'] >= 2 * $total['cpr']) {
                $rows[] = ['reklam' => (string) ($ctx['e']['ads'][$id]['name'] ?? $id), 'harcama' => ($ctx['money'])($ad['spend']), 'sonuç' => 0, 'hesap_sonuç_başı' => ($ctx['money'])($total['cpr'])];
            }
        }
        if ($rows === []) {
            return ['state' => 'ok', 'detail' => 'Sonuç başı maliyetin 2 katı harcayıp sonuç getirmeyen reklam yok.'];
        }

        return $this->issue('Sonuç getirmeyen reklam: '.count($rows), count($rows).' reklam hesabın sonuç başı maliyetinin 2 katından fazla harcadı, 0 sonuç.', 2, $rows,
            'Bu reklamları kapatın ya da kreatifini değiştirin.');
    }

    /**
     * Ad-count ceiling (marketingskills ads / Meta decision system): over 14 days each ad needs about 2 × the cost per
     * result to be judged, so a campaign feeds spend of 14 days ÷ (2 × cost per result) ads; more starve each other.
     */
    private function checkAdCount(array $ctx): array
    {
        $total = $ctx['cur_total'];
        if ($total['results'] < self::MIN_ACCOUNT_RESULTS || $total['cpr'] === null) {
            return ['state' => $total['spend'] > 0 ? 'low_data' : 'no_data', 'detail' => 'Hesapta '.(int) $total['results'].' sonuç; reklam tavanı için veri az.'];
        }
        $campaigns = [];
        foreach ($ctx['last14'] as $ad) {
            if ($ad['spend'] > 0) {
                $campaigns[$ad['campaign_id']]['ads'] = ($campaigns[$ad['campaign_id']]['ads'] ?? 0) + 1;
                $campaigns[$ad['campaign_id']]['spend'] = ($campaigns[$ad['campaign_id']]['spend'] ?? 0) + $ad['spend'];
            }
        }
        if ($campaigns === []) {
            return ['state' => 'no_data', 'detail' => 'Son 14 günde harcama yok.'];
        }
        $rows = [];
        foreach ($campaigns as $id => $c) {
            $ceiling = max(1, (int) floor($c['spend'] / (2 * $total['cpr'])));
            if ($c['ads'] >= 3 && $c['ads'] > $ceiling) {
                $rows[] = ['kampanya' => (string) ($ctx['e']['campaigns'][$id]['name'] ?? $id), 'aktif_reklam' => $c['ads'], 'kaldırabileceği' => $ceiling, '14_gün_harcama' => ($ctx['money'])($c['spend'])];
            }
        }
        if ($rows === []) {
            return ['state' => 'ok', 'detail' => 'Kampanyaların reklam sayısı bütçeyle uyumlu.'];
        }

        return $this->issue('Bütçeye göre çok reklam: '.count($rows).' kampanya', count($rows).' kampanyada reklam sayısı 14 günlük harcamanın besleyebileceğinden fazla (reklam başına 2 × sonuç başı maliyet).', 3, $rows,
            'Bu kampanyalarda en az sonuç getiren reklamları kapatıp sayıyı tavana indirin; yeni test eklemeden önce birini kapatın.');
    }

    /**
     * Delivery check (marketingskills ads / Meta decision system, stage 1): an ad running at least a week that gets
     * under half of its fair share of the campaign's spend in the last 7 days was pushed back by Meta.
     */
    private function checkStarved(array $ctx): array
    {
        $byCampaign = [];
        foreach ($ctx['e']['ads'] as $id => $ad) {
            $ranBefore = ($ctx['ads'][$id]['spend'] ?? 0) > ($ctx['week'][$id]['spend'] ?? 0);
            if ($ad['status'] === 'ACTIVE' && $ranBefore) {
                $byCampaign[$ad['campaign_id']][] = $id;
            }
        }
        $rows = [];
        $checked = 0;
        foreach ($byCampaign as $campaignId => $ids) {
            $spend = array_sum(array_map(fn (string $id): float => (float) ($ctx['week'][$id]['spend'] ?? 0), $ids));
            if (count($ids) < 2 || $spend <= 0) {
                continue;
            }
            $checked++;
            $share = $spend / count($ids);
            foreach ($ids as $id) {
                $adSpend = (float) ($ctx['week'][$id]['spend'] ?? 0);
                if ($adSpend < 0.5 * $share) {
                    $rows[] = ['reklam' => (string) ($ctx['e']['ads'][$id]['name'] ?? $id), 'kampanya' => (string) ($ctx['e']['campaigns'][$campaignId]['name'] ?? $campaignId),
                        'son_7_gün' => ($ctx['money'])($adSpend), 'adil_pay' => ($ctx['money'])($share)];
                }
            }
        }
        if ($checked === 0) {
            return ['state' => 'no_data', 'detail' => 'Birden çok aktif reklamı olan kampanya yok.'];
        }
        if ($rows === []) {
            return ['state' => 'ok', 'detail' => 'Aktif reklamlar kampanya bütçesinden pay alıyor.'];
        }

        return $this->issue('Bütçe almayan reklam: '.count($rows), count($rows).' reklam son 7 günde kampanyadaki adil payının yarısından az harcadı; Meta onu geri plana itmiş.', 3, $rows,
            'Bu reklamları kapatın; yerine kancası ya da görseli değişmiş yeni bir sürüm koyun (metni değiştirmek yetmez).');
    }

    /** @return list<array{ad: string, link_url: string, lead_gen_form_id: string}> creatives of the ads that spent in the window */
    private function activeCreatives(array $ctx): array
    {
        $out = [];
        foreach ($ctx['ads'] as $id => $row) {
            if ($row['spend'] <= 0) {
                continue;
            }
            $ad = $ctx['e']['ads'][$id] ?? null;
            $creative = $ctx['e']['creatives'][(string) ($ad['creative_id'] ?? '')] ?? null;
            if ($creative !== null) {
                $out[] = ['ad' => (string) ($ad['name'] ?? $id), 'link_url' => trim((string) $creative['link_url']), 'lead_gen_form_id' => (string) $creative['lead_gen_form_id']];
            }
        }

        return $out;
    }

    /** @param  list<array<string, mixed>>  $rows */
    private function issue(string $title, string $detail, int $priority, array $rows, string $todo): array
    {
        return ['state' => 'issue', 'title' => $title, 'detail' => $detail, 'priority' => $priority, 'rows' => $rows, 'todo' => $todo];
    }

    private static function pct(?float $value): string
    {
        return $value === null ? '—' : ($value > 0 ? '+' : '').$value.'%';
    }

    public static function host(string $url): string
    {
        $url = str_contains($url, '://') ? $url : 'https://'.$url;

        return preg_replace('/^www\./', '', strtolower((string) parse_url($url, PHP_URL_HOST))) ?? '';
    }

    public static function normalizeUrl(string $url): string
    {
        $path = rtrim((string) parse_url($url, PHP_URL_PATH), '/');

        return self::host($url).($path === '' ? '/' : $path);
    }
}
