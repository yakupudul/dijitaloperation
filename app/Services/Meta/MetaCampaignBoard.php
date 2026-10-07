<?php

namespace App\Services\Meta;

use App\Models\DigitalAsset;
use App\Models\MetaLead;
use App\Models\MetaLeadForm;
use App\Services\Ads\AdServiceStats;
use App\Services\Site\Analysis\SiteRange;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Schema;

/**
 * Meta hesap sayfası › Kampanyalar and Kampanya detayı: every campaign of the bound ad account with its services
 * (MetaCampaignServices), budget, spend, results of its own result type (form, mesaj, satış, tıklama; never added
 * together), cost per result against the previous window and its alerts. Numbers come from MetaScreen (collected
 * tables only); nothing is written to Meta.
 *
 * @phpstan-import-type Account from MetaScreen
 */
final class MetaCampaignBoard
{
    /** Result types: label of one result and of its cost. */
    public const array TYPES = [
        'leads' => ['form', 'form başı'],
        'messages' => ['mesaj', 'mesaj başı'],
        'purchases' => ['satış', 'satış başı'],
        'clicks' => ['tıklama', 'tıklama başı'],
        'impressions' => ['gösterim', '1000 gösterim'],
    ];

    /** Alert: cost per result up at least this much (%) on at least MIN_RESULTS results. */
    public const float COST_UP_PCT = 30.0;

    public const int MIN_RESULTS = 5;

    /** Alert: a running campaign spent nothing in its last this many days of the window. */
    public const int STOPPED_DAYS = 3;

    public function __construct(private readonly MetaScreen $screen, private readonly MetaCampaignServices $services) {}

    /**
     * @return array{bound: bool, window: array<string, string>, kpis: array<string, array<string, mixed>>, rows: list<array<string, mixed>>, offerings: list<array{id: int, name: string}>, open: int}
     */
    public function board(DigitalAsset $asset, int|SiteRange $days): array
    {
        $account = $this->screen->account($asset);
        $offerings = $asset->brand !== null ? array_map(fn (array $o): array => ['id' => $o['id'], 'name' => $o['name']], $this->services->offerings($asset->brand)) : [];
        if ($account === null) {
            return ['bound' => false, 'window' => [], 'kpis' => [], 'rows' => [], 'offerings' => $offerings, 'open' => 0];
        }
        $w = $this->screen->window($account, $days);
        $entities = $this->screen->entities($account);
        $current = MetaScreen::rollup($this->screen->adPerformance($account, $w['from'], $w['to'], $entities), 'campaign_id');
        $previous = MetaScreen::rollup($this->screen->adPerformance($account, $w['prev_from'], $w['prev_to'], $entities), 'campaign_id');
        $recentFrom = CarbonImmutable::parse($w['to'])->subDays(self::STOPPED_DAYS - 1)->toDateString();
        $recent = MetaScreen::rollup($this->screen->adPerformance($account, $recentFrom, $w['to'], $entities), 'campaign_id');
        $map = $this->services->map($asset);
        $issues = $this->adIssues($account, $entities);
        $rows = [];
        foreach ($entities['campaigns'] as $id => $campaign) {
            $cur = $current[$id] ?? null;
            $prev = $previous[$id] ?? null;
            $status = self::status($campaign['status']);
            $type = self::type($cur ?? $prev ?? [], $campaign['objective']);
            [$count, $cpr] = self::result($cur, $type);
            [, $prevCpr] = self::result($prev, $type);
            $entry = $map[$id] ?? ['state' => MetaCampaignServices::STATE_NONE, 'services' => []];
            $alerts = [];
            if ($entry['state'] === MetaCampaignServices::STATE_NONE && $status !== 'ended') {
                $alerts[] = ['key' => 'no_service', 'label' => 'Hizmet yok', 'tone' => 'warn'];
            }
            if ($status === 'live' && ($cur['spend'] ?? 0) > 0 && ($recent[$id]['spend'] ?? 0) <= 0) {
                $alerts[] = ['key' => 'stopped', 'label' => 'Harcama durdu', 'tone' => 'bad'];
            }
            $change = MetaScreen::change($cpr, $prevCpr);
            if ($change !== null && $change >= self::COST_UP_PCT && $count >= self::MIN_RESULTS) {
                $alerts[] = ['key' => 'cost_up', 'label' => 'Maliyet arttı', 'tone' => 'bad'];
            }
            foreach (['disapproved' => ['Reddedilen reklam', 'bad'], 'fatigue' => ['Kreatif yoruldu', 'warn']] as $key => [$label, $tone]) {
                if (isset($issues[$key][$id])) {
                    $alerts[] = ['key' => $key, 'label' => $label, 'tone' => $tone];
                }
            }
            $rows[] = [
                'id' => (string) $id, 'name' => $campaign['name'], 'objective' => MetaScreen::objectiveLabel($campaign['objective']), 'status' => $status,
                'budget' => $this->budget($campaign, $entities), 'spend' => round((float) ($cur['spend'] ?? 0), 2), 'type' => $type,
                'results' => $count, 'cpr' => $cpr, 'prev_cpr' => $prevCpr, 'cpr_change' => $change,
                'service_state' => $entry['state'], 'services' => $entry['services'], 'alerts' => $alerts,
            ];
        }
        usort($rows, fn (array $a, array $b): int => [self::statusOrder($a['status']), -$a['spend']] <=> [self::statusOrder($b['status']), -$b['spend']]);
        $rows = $this->withAverages($asset, $rows);

        return ['bound' => true, 'window' => $w, 'kpis' => $this->kpis($current, $previous, $rows), 'rows' => $rows, 'offerings' => $offerings,
            'by_service' => self::byService($rows),
            'open' => count(array_filter($rows, fn (array $r): bool => in_array($r['service_state'], [MetaCampaignServices::STATE_NONE, MetaCampaignServices::STATE_SUGGESTED], true) && $r['status'] !== 'ended'))];
    }

    /**
     * Hizmetlere göre: every service with its campaigns (live and not), spend and results of its main result type in the
     * window. A campaign with two services counts under both.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return list<array{id: int, name: string, campaigns: int, live: int, spend: float, type: string, results: float, cpr: ?float}>
     */
    public static function byService(array $rows): array
    {
        $out = [];
        foreach ($rows as $row) {
            foreach ($row['services'] as $service) {
                $id = (int) $service['id'];
                $out[$id] ??= ['id' => $id, 'name' => (string) $service['name'], 'campaigns' => 0, 'live' => 0, 'spend' => 0.0, 'by_type' => []];
                $out[$id]['campaigns']++;
                $out[$id]['live'] += $row['status'] === 'live' ? 1 : 0;
                $out[$id]['spend'] += (float) $row['spend'];
                $out[$id]['by_type'][$row['type']]['spend'] = ($out[$id]['by_type'][$row['type']]['spend'] ?? 0.0) + (float) $row['spend'];
                $out[$id]['by_type'][$row['type']]['results'] = ($out[$id]['by_type'][$row['type']]['results'] ?? 0.0) + (float) $row['results'];
            }
        }
        $list = [];
        foreach ($out as $service) {
            $type = 'leads';
            $best = -1.0;
            foreach ($service['by_type'] as $key => $n) {
                if ($n['results'] > $best) {
                    [$type, $best] = [(string) $key, (float) $n['results']];
                }
            }
            $n = $service['by_type'][$type] ?? ['spend' => 0.0, 'results' => 0.0];
            $list[] = ['id' => $service['id'], 'name' => $service['name'], 'campaigns' => $service['campaigns'], 'live' => $service['live'], 'spend' => round($service['spend'], 2),
                'type' => $type, 'results' => (float) $n['results'], 'cpr' => $n['results'] > 0 ? round($n['spend'] / $n['results'], 2) : null];
        }
        usort($list, fn (array $a, array $b): int => [$b['spend'], $b['campaigns']] <=> [$a['spend'], $a['campaigns']]);

        return $list;
    }

    /**
     * Kampanya detayı: settings, ad sets with their targeting, ads with their creative, a 60-day day-by-day series with
     * the account's change events, and the window numbers.
     *
     * @return array<string, mixed>|null null when the campaign is not in the account
     */
    public function campaign(DigitalAsset $asset, string $campaignId, int|SiteRange $days): ?array
    {
        $account = $this->screen->account($asset);
        if ($account === null) {
            return null;
        }
        $entities = $this->screen->entities($account);
        $campaign = $entities['campaigns'][$campaignId] ?? null;
        if ($campaign === null) {
            return null;
        }
        $w = $this->screen->window($account, $days);
        $ads = array_filter($this->screen->adPerformance($account, $w['from'], $w['to'], $entities), fn (array $r): bool => $r['campaign_id'] === $campaignId);
        $prevAds = array_filter($this->screen->adPerformance($account, $w['prev_from'], $w['prev_to'], $entities), fn (array $r): bool => $r['campaign_id'] === $campaignId);
        $total = MetaScreen::totals($ads);
        $type = self::type($total, $campaign['objective']);
        [$count, $cpr] = self::result($total, $type);
        [$prevCount, $prevCpr] = self::result(MetaScreen::totals($prevAds), $type);
        $frequency = array_filter(array_column($ads, 'frequency'));
        $meta = $this->metadata($account, $campaignId);
        $adIds = array_keys(array_filter($entities['ads'], fn (array $ad): bool => $ad['campaign_id'] === $campaignId));
        $end = CarbonImmutable::parse($w['to']);
        $seriesFrom = $end->subDays(59)->toDateString();
        $fatigue = $this->screen->fatigue($account);

        $adsets = [];
        $bySet = MetaScreen::rollup($ads, 'adset_id');
        foreach ($entities['adsets'] as $id => $adset) {
            if ($adset['campaign_id'] !== $campaignId) {
                continue;
            }
            [$setCount, $setCpr] = self::result($bySet[$id] ?? null, $type);
            $adsets[] = ['id' => (string) $id, 'name' => $adset['name'], 'status' => self::status($adset['status']), 'optimization' => $adset['optimization_goal'],
                'destination' => $adset['destination_type'], 'budget' => $adset['daily_budget'] !== null ? round($adset['daily_budget'], 2) : null,
                'attribution' => MetaScreen::attributionLabel($adset['attribution_spec']), 'targeting' => self::targeting($adset['targeting']),
                'spend' => round((float) ($bySet[$id]['spend'] ?? 0), 2), 'results' => $setCount, 'cpr' => $setCpr];
        }
        usort($adsets, fn (array $a, array $b): int => $b['spend'] <=> $a['spend']);

        $adRows = [];
        foreach ($adIds as $id) {
            $ad = $entities['ads'][$id];
            $creative = $entities['creatives'][$ad['creative_id']] ?? [];
            [$adCount, $adCpr] = self::result($ads[$id] ?? null, $type);
            $adRows[] = ['id' => (string) $id, 'name' => $ad['name'], 'status' => (string) $ad['status'], 'adset' => (string) ($entities['adsets'][$ad['adset_id']]['name'] ?? ''),
                'title' => (string) ($creative['title'] ?? ''), 'body' => (string) ($creative['body'] ?? ''), 'link_url' => (string) ($creative['link_url'] ?? ''),
                'thumbnail_url' => (string) ($creative['thumbnail_url'] ?? ''), 'video' => (bool) ($creative['video'] ?? false),
                // A lead ad set ("ON_AD") always opens Meta's instant form, even when the creative does not name it.
                'form' => ($creative['lead_gen_form_id'] ?? '') !== '' || ($entities['adsets'][$ad['adset_id']]['destination_type'] ?? '') === 'ON_AD',
                'whatsapp' => ($creative['whatsapp_number'] ?? '') !== '' || strtoupper((string) ($creative['cta'] ?? '')) === 'WHATSAPP_MESSAGE'
                    || str_contains(strtoupper((string) ($entities['adsets'][$ad['adset_id']]['destination_type'] ?? '')), 'WHATSAPP'),
                'spend' => round((float) ($ads[$id]['spend'] ?? 0), 2), 'results' => $adCount, 'cpr' => $adCpr,
                'ctr' => isset($ads[$id]) ? MetaScreen::derive($ads[$id])['ctr'] : null, 'fatigue' => $fatigue[(string) $id] ?? null];
        }
        usort($adRows, fn (array $a, array $b): int => [$a['cpr'] === null ? 1 : 0, $a['cpr'] ?? 0, -$a['spend']] <=> [$b['cpr'] === null ? 1 : 0, $b['cpr'] ?? 0, -$b['spend']]);
        if (($adRows[0]['cpr'] ?? null) !== null && count($adRows) > 1) {
            $adRows[0]['best'] = true;
        }

        return [
            'id' => $campaignId, 'name' => $campaign['name'], 'status' => self::status($campaign['status']), 'raw_status' => $campaign['status'],
            'objective' => MetaScreen::objectiveLabel($campaign['objective']), 'type' => $type, 'window' => $w,
            'settings' => [
                'buying_type' => (string) ($meta['buying_type'] ?? ''), 'budget' => $this->budget($campaign, $entities),
                'lifetime_budget' => is_numeric($meta['lifetime_budget'] ?? null) && (float) $meta['lifetime_budget'] > 0 ? round((float) $meta['lifetime_budget'], 2) : null,
                'start' => self::date($meta['start_time'] ?? null), 'stop' => self::date($meta['stop_time'] ?? null),
            ],
            'kpis' => ['spend' => $total['spend'], 'prev_spend' => round((float) array_sum(array_column($prevAds, 'spend')), 2), 'results' => $count, 'prev_results' => $prevCount,
                'cpr' => $cpr, 'prev_cpr' => $prevCpr, 'ctr' => $total['ctr'], 'frequency' => $frequency === [] ? null : round(array_sum($frequency) / count($frequency), 2),
                'cpm' => $total['impressions'] > 0 ? round($total['spend'] / $total['impressions'] * 1000, 2) : null],
            'series' => $this->screen->dailySeries($account, $adIds, $seriesFrom, $w['to']),
            'events' => $this->events($account, array_merge([$campaignId], array_column($adsets, 'id'), $adIds), $seriesFrom, $w['to']),
            'adsets' => $adsets, 'ads' => $adRows,
            'ads_manager_url' => 'https://adsmanager.facebook.com/adsmanager/manage/campaigns?act='.rawurlencode($account['account_id']).'&selected_campaign_ids='.rawurlencode($campaignId),
        ];
    }

    /**
     * Reklam detayı: one ad of a campaign with everything that shows what it says and where it leads. The creative
     * (every text, its variations, the button), the destination (the instant form's structure; the WhatsApp number and
     * greeting; the site link with its UTM), the window numbers against the previous window, a 60-day series, the
     * other ads of its ad set and the operator's lead marks of the ad. The people who filled in a form are never read.
     *
     * @return array<string, mixed>|null null when the ad is not in the campaign
     */
    public function ad(DigitalAsset $asset, string $campaignId, string $adId, int|SiteRange $days): ?array
    {
        $account = $this->screen->account($asset);
        if ($account === null) {
            return null;
        }
        $entities = $this->screen->entities($account);
        $ad = $entities['ads'][$adId] ?? null;
        $campaign = $entities['campaigns'][$campaignId] ?? null;
        if ($ad === null || $campaign === null || $ad['campaign_id'] !== $campaignId) {
            return null;
        }
        $creative = $entities['creatives'][$ad['creative_id']] ?? [];
        $adset = $entities['adsets'][$ad['adset_id']] ?? [];
        $w = $this->screen->window($account, $days);
        $current = $this->screen->adPerformance($account, $w['from'], $w['to'], $entities);
        $previous = $this->screen->adPerformance($account, $w['prev_from'], $w['prev_to'], $entities);
        $type = self::type(MetaScreen::totals(array_filter($current, fn (array $r): bool => $r['campaign_id'] === $campaignId)), $campaign['objective']);
        $row = MetaScreen::derive(($current[$adId] ?? []) + ['spend' => 0.0, 'impressions' => 0, 'clicks' => 0, 'results' => 0.0, 'leads' => 0.0, 'messages' => 0.0, 'purchases' => 0.0, 'frequency' => null]);
        $prev = isset($previous[$adId]) ? MetaScreen::derive($previous[$adId]) : null;
        [$count, $cpr] = self::result($row, $type);
        [$prevCount, $prevCpr] = self::result($prev, $type);

        $siblings = [];
        foreach ($entities['ads'] as $id => $other) {
            if ($other['adset_id'] !== $ad['adset_id']) {
                continue;
            }
            [$c, $p] = self::result($current[$id] ?? null, $type);
            $siblings[] = ['id' => (string) $id, 'name' => $other['name'], 'status' => self::status($other['status']), 'results' => $c, 'cpr' => $p,
                'spend' => round((float) ($current[$id]['spend'] ?? 0), 2), 'self' => (string) $id === $adId];
        }
        usort($siblings, fn (array $a, array $b): int => [$a['cpr'] === null ? 1 : 0, $a['cpr'] ?? 0, -$a['spend']] <=> [$b['cpr'] === null ? 1 : 0, $b['cpr'] ?? 0, -$b['spend']]);

        $end = CarbonImmutable::parse($w['to']);
        $link = (string) ($creative['link_url'] ?? '');

        return [
            'id' => $adId, 'name' => $ad['name'], 'status' => self::status($ad['status']), 'raw_status' => $ad['status'], 'type' => $type, 'window' => $w,
            'campaign' => ['id' => $campaignId, 'name' => $campaign['name']],
            'adset' => ['id' => (string) $ad['adset_id'], 'name' => (string) ($adset['name'] ?? ''), 'optimization' => (string) ($adset['optimization_goal'] ?? ''),
                'destination' => (string) ($adset['destination_type'] ?? ''), 'targeting' => self::targeting((array) ($adset['targeting'] ?? []))],
            'creative' => [
                'thumbnail_url' => (string) ($creative['thumbnail_url'] ?? ''), 'image_url' => (string) ($creative['image_url'] ?? ''), 'video' => (bool) ($creative['video'] ?? false),
                'body' => (string) ($creative['body'] ?? ''), 'title' => (string) ($creative['title'] ?? ''), 'description' => (string) ($creative['description'] ?? ''),
                'cta' => self::ctaLabel((string) ($creative['cta'] ?? '')), 'variants' => self::variants((array) ($creative['variants'] ?? []), $creative),
                'post_url' => ($creative['post_id'] ?? '') !== '' ? 'https://www.facebook.com/'.rawurlencode((string) $creative['post_id']) : '',
            ],
            'destination' => $this->destination($account, $creative, (string) ($adset['destination_type'] ?? ''), $link),
            'kpis' => ['spend' => $row['spend'], 'prev_spend' => $prev['spend'] ?? null, 'results' => $count, 'prev_results' => $prev !== null ? $prevCount : null,
                'cpr' => $cpr, 'prev_cpr' => $prevCpr, 'ctr' => $row['ctr'], 'prev_ctr' => $prev['ctr'] ?? null, 'impressions' => (int) $row['impressions'],
                'clicks' => (int) $row['clicks'], 'frequency' => $row['frequency'] ?? null,
                'cpm' => $row['impressions'] > 0 ? round($row['spend'] / $row['impressions'] * 1000, 2) : null],
            'series' => $this->screen->dailySeries($account, [$adId], $end->subDays(59)->toDateString(), $w['to']),
            'siblings' => $siblings,
            'marks' => $this->leadMarks($asset, $ad['name']),
            'fatigue' => $this->screen->fatigue($account)[$adId] ?? null,
            'ads_manager_url' => 'https://adsmanager.facebook.com/adsmanager/manage/ads?act='.rawurlencode($account['account_id']).'&selected_ad_ids='.rawurlencode($adId),
        ];
    }

    /** Turkish label of a Meta call-to-action button. */
    public static function ctaLabel(string $cta): string
    {
        return match (strtoupper($cta)) {
            '' => '',
            'LEARN_MORE' => 'Daha fazla bilgi al',
            'SIGN_UP' => 'Kaydol',
            'APPLY_NOW' => 'Hemen başvur',
            'GET_QUOTE' => 'Fiyat teklifi al',
            'BOOK_NOW', 'BOOK_TRAVEL' => 'Hemen rezervasyon yap',
            'CONTACT_US' => 'Bize ulaşın',
            'CALL_NOW' => 'Hemen ara',
            'WHATSAPP_MESSAGE' => 'WhatsApp’tan mesaj gönder',
            'MESSAGE_PAGE', 'SEND_MESSAGE' => 'Mesaj gönder',
            'INSTAGRAM_MESSAGE' => 'Instagram’dan mesaj gönder',
            'SHOP_NOW' => 'Alışverişe başla',
            'GET_OFFER' => 'Teklifi al',
            'SUBSCRIBE' => 'Abone ol',
            'DOWNLOAD' => 'İndir',
            'NO_BUTTON' => 'Buton yok',
            default => ucfirst(mb_strtolower(str_replace('_', ' ', $cta))),
        };
    }

    /**
     * Text variations other than the ones shown as the main text (dynamic creatives).
     *
     * @param  array<string, mixed>  $variants
     * @param  array<string, mixed>  $creative
     * @return array{bodies: list<string>, titles: list<string>, descriptions: list<string>}
     */
    private static function variants(array $variants, array $creative): array
    {
        $out = [];
        foreach (['bodies' => 'body', 'titles' => 'title', 'descriptions' => 'description'] as $key => $main) {
            $out[$key] = array_values(array_filter((array) ($variants[$key] ?? []), fn (mixed $t): bool => is_string($t) && $t !== '' && $t !== ($creative[$main] ?? '')));
        }

        return $out;
    }

    /**
     * Where the ad leads: an instant form (its stored structure), WhatsApp (number, greeting), Messenger / Instagram
     * Direct, a site page (with its UTM parameters), or nothing found.
     *
     * @param  array<string, mixed>  $creative
     * @return array<string, mixed>
     */
    private function destination(array $account, array $creative, string $destinationType, string $link): array
    {
        $cta = strtoupper((string) ($creative['cta'] ?? ''));
        $formId = (string) ($creative['lead_gen_form_id'] ?? '');
        if ($formId !== '' || strtoupper($destinationType) === 'ON_AD') {
            $form = $formId !== '' && Schema::hasTable('meta_lead_forms')
                ? MetaLeadForm::query()->where('account_id', $account['account_id'])->where('form_id', $formId)->first() : null;

            return ['kind' => 'form', 'form_id' => $formId, 'form' => $form === null ? null : [
                'name' => (string) $form->name, 'status' => (string) $form->status, 'locale' => (string) $form->locale, 'intro' => $form->intro ?? [],
                'questions' => $form->questions ?? [], 'thank_you' => $form->thank_you ?? [], 'privacy_policy_url' => (string) $form->privacy_policy_url,
                'error' => (string) $form->error, 'fetched_at' => $form->fetched_at?->toDateString(),
            ]];
        }
        if (str_contains(strtoupper($destinationType), 'WHATSAPP') || $cta === 'WHATSAPP_MESSAGE' || ($creative['whatsapp_number'] ?? '') !== '') {
            return ['kind' => 'whatsapp', 'number' => (string) ($creative['whatsapp_number'] ?? ''), 'welcome' => (string) ($creative['welcome_message'] ?? ''),
                'page_id' => (string) ($creative['page_id'] ?? '')];
        }
        if (preg_match('/MESSENGER|INSTAGRAM_DIRECT|MESSAG/i', $destinationType) === 1 || in_array($cta, ['MESSAGE_PAGE', 'SEND_MESSAGE', 'INSTAGRAM_MESSAGE'], true)) {
            return ['kind' => 'message', 'channel' => str_contains(strtoupper($destinationType.$cta), 'INSTAGRAM') ? 'Instagram Direct' : 'Messenger',
                'welcome' => (string) ($creative['welcome_message'] ?? '')];
        }
        if ($link !== '') {
            parse_str((string) parse_url($link, PHP_URL_QUERY), $query);
            $utm = array_filter($query, fn (mixed $v, string|int $k): bool => is_string($v) && str_starts_with((string) $k, 'utm_'), ARRAY_FILTER_USE_BOTH);

            return ['kind' => 'site', 'url' => $link, 'host' => (string) parse_url($link, PHP_URL_HOST), 'path' => (string) (parse_url($link, PHP_URL_PATH) ?: '/'), 'utm' => $utm];
        }

        return ['kind' => 'none'];
    }

    /**
     * The operator's marks of the ad's leads (Form kalitesi import: lead id, date and the mark only; no contact data).
     *
     * @return array{total: int, uygun: int, randevu: int, satis: int, uygunsuz: int, unmarked: int}|null null when no lead of the ad was imported
     */
    private function leadMarks(DigitalAsset $asset, string $adName): ?array
    {
        if (! Schema::hasTable('meta_leads')) {
            return null;
        }
        $rows = MetaLead::query()->where('digital_asset_id', $asset->id)->where('ad_name', $adName)->selectRaw('mark, count(*) as n')->groupBy('mark')->pluck('n', 'mark');
        if ($rows->isEmpty()) {
            return null;
        }
        $out = ['total' => (int) $rows->sum(), 'uygun' => 0, 'randevu' => 0, 'satis' => 0, 'uygunsuz' => 0, 'unmarked' => 0];
        foreach ($rows as $mark => $n) {
            $out[isset(MetaLead::MARKS[(string) $mark]) ? (string) $mark : 'unmarked'] += (int) $n;
        }

        return $out;
    }

    /**
     * The campaign's recipe for Strateji öner / Kazananlar: settings, the targeting of its biggest ad set and its best
     * ad (lowest cost per result of the campaign's type, else the biggest spender). Rule-built from the collected tables.
     *
     * @param  array<string, array<string, mixed>>  $ads  adPerformance() of the window
     * @param  array{amount: ?float, level: string}  $budget
     * @return array<string, mixed>
     */
    public static function profile(string $campaignId, array $entities, array $ads, string $type, array $budget): array
    {
        $campaign = $entities['campaigns'][$campaignId] ?? ['objective' => ''];
        $own = array_filter($ads, fn (array $r): bool => $r['campaign_id'] === $campaignId);
        $bySet = MetaScreen::rollup($own, 'adset_id');
        $adsets = array_filter($entities['adsets'], fn (array $a): bool => $a['campaign_id'] === $campaignId);
        uksort($adsets, fn ($a, $b): int => ($bySet[$b]['spend'] ?? 0) <=> ($bySet[$a]['spend'] ?? 0));
        $main = $adsets === [] ? null : reset($adsets);
        $campaignAds = array_filter($entities['ads'], fn (array $ad): bool => $ad['campaign_id'] === $campaignId);
        $best = null;
        $destinations = [];
        $videos = 0;
        foreach ($campaignAds as $id => $ad) {
            $creative = $entities['creatives'][$ad['creative_id']] ?? [];
            $videos += ($creative['video'] ?? false) ? 1 : 0;
            $set = $entities['adsets'][$ad['adset_id']] ?? [];
            $destinations[] = ($creative['lead_gen_form_id'] ?? '') !== '' || ($set['destination_type'] ?? '') === 'ON_AD' ? 'form'
                : (preg_match('/MESSENGER|WHATSAPP|INSTAGRAM_DIRECT|MESSAG/i', (string) ($set['destination_type'] ?? '')) ? 'mesaj' : (($creative['link_url'] ?? '') !== '' ? 'site' : ''));
            [$count, $cpr] = self::result($ads[$id] ?? null, $type);
            $spend = (float) ($ads[$id]['spend'] ?? 0);
            $rank = [$cpr === null ? 1 : 0, $cpr ?? 0, -$spend];
            if (($creative['body'] ?? '') === '' && ($creative['title'] ?? '') === '') {
                continue;
            }
            if ($best === null || $rank < $best['rank']) {
                $best = ['rank' => $rank, 'title' => mb_substr((string) ($creative['title'] ?? ''), 0, 200), 'body' => mb_substr((string) ($creative['body'] ?? ''), 0, 1200),
                    'video' => (bool) ($creative['video'] ?? false), 'form' => ($creative['lead_gen_form_id'] ?? '') !== '',
                    'link_path' => ($creative['link_url'] ?? '') !== '' ? (string) (parse_url((string) $creative['link_url'], PHP_URL_PATH) ?: '/') : '',
                    'results' => $count, 'cpr' => $cpr];
            }
        }
        if ($best !== null) {
            unset($best['rank']);
        }
        $destinations = array_count_values(array_filter($destinations));
        arsort($destinations);
        $targeting = $main !== null ? self::targeting((array) $main['targeting']) : null;
        if ($targeting !== null) {
            $targeting['interests'] = array_slice($targeting['interests'], 0, 15);
            $targeting['audiences'] = count($targeting['audiences']);
            $targeting['excluded'] = count($targeting['excluded']);
        }

        return [
            'objective' => MetaScreen::objectiveLabel((string) $campaign['objective']),
            'optimization' => array_values(array_unique(array_filter(array_column($adsets, 'optimization_goal')))),
            'destination' => (string) (array_key_first($destinations) ?? ''),
            'budget' => $budget['amount'], 'budget_level' => $budget['level'],
            'adsets' => count(array_filter($adsets, fn (array $a): bool => self::status((string) $a['status']) === 'live')) ?: count($adsets),
            'ads' => count($campaignAds), 'video_ads' => $videos,
            'targeting' => $targeting, 'best_ad' => $best,
        ];
    }

    /** ACTIVE → live, paused at any level → paused, deleted / archived → ended. */
    public static function status(string $status): string
    {
        return match (strtoupper($status)) {
            'PAUSED', 'CAMPAIGN_PAUSED', 'ADSET_PAUSED' => 'paused',
            'DELETED', 'ARCHIVED' => 'ended',
            default => 'live',
        };
    }

    /** The campaign's own result type: its largest action, else from its objective. */
    public static function type(array $row, string $objective): string
    {
        $best = null;
        foreach (['leads', 'messages', 'purchases'] as $key) {
            if (($row[$key] ?? 0) > 0 && ($best === null || $row[$key] > $row[$best])) {
                $best = $key;
            }
        }

        return $best ?? match ($objective) {
            'OUTCOME_LEADS', 'LEAD_GENERATION' => 'leads',
            'OUTCOME_ENGAGEMENT', 'MESSAGES' => 'messages',
            'OUTCOME_SALES', 'CONVERSIONS' => 'purchases',
            'OUTCOME_AWARENESS', 'REACH', 'BRAND_AWARENESS', 'POST_ENGAGEMENT', 'VIDEO_VIEWS' => 'impressions',
            default => 'clicks',
        };
    }

    /**
     * Count of the type and its cost (impressions: cost per 1000).
     *
     * @return array{0: float, 1: ?float}
     */
    public static function result(?array $row, string $type): array
    {
        if ($row === null) {
            return [0.0, null];
        }
        $count = (float) ($row[$type] ?? 0);
        $spend = (float) ($row['spend'] ?? 0);
        if ($count <= 0) {
            return [$count, null];
        }

        return [$count, round($type === 'impressions' ? $spend / $count * 1000 : $spend / $count, 2)];
    }

    /**
     * Readable targeting of an ad set.
     *
     * @param  array<string, mixed>  $t
     * @return array{locations: list<string>, age: string, genders: string, interests: list<string>, audiences: list<string>, excluded: list<string>, placements: string, advantage: bool}
     */
    public static function targeting(array $t): array
    {
        $geo = (array) ($t['geo_locations'] ?? []);
        $locations = [];
        foreach ((array) ($geo['cities'] ?? []) as $city) {
            $radius = isset($city['radius']) ? ' +'.$city['radius'].' '.(($city['distance_unit'] ?? 'kilometer') === 'mile' ? 'mil' : 'km') : '';
            $locations[] = (string) ($city['name'] ?? '').$radius;
        }
        foreach ((array) ($geo['regions'] ?? []) as $region) {
            $locations[] = (string) ($region['name'] ?? '');
        }
        foreach ((array) ($geo['custom_locations'] ?? []) as $place) {
            $locations[] = trim((string) ($place['name'] ?? $place['address_string'] ?? 'Özel konum').(isset($place['radius']) ? ' +'.$place['radius'].' km' : ''));
        }
        foreach ((array) ($geo['countries'] ?? []) as $country) {
            $locations[] = (string) $country;
        }
        $interests = [];
        foreach (array_merge([$t], (array) ($t['flexible_spec'] ?? [])) as $spec) {
            foreach (['interests', 'behaviors', 'work_positions', 'life_events', 'family_statuses'] as $field) {
                foreach ((array) ($spec[$field] ?? []) as $item) {
                    $interests[] = (string) ($item['name'] ?? '');
                }
            }
        }
        $genders = array_map('intval', (array) ($t['genders'] ?? []));
        $positions = array_merge((array) ($t['facebook_positions'] ?? []), (array) ($t['instagram_positions'] ?? []));
        $platforms = (array) ($t['publisher_platforms'] ?? []);
        $min = $t['age_min'] ?? null;
        $max = $t['age_max'] ?? null;

        return [
            'locations' => array_values(array_filter($locations)),
            'age' => $min === null && $max === null ? '18–65+' : ($min ?? 18).'–'.(($max ?? 65) >= 65 ? '65+' : $max),
            'genders' => $genders === [1] ? 'Erkek' : ($genders === [2] ? 'Kadın' : 'Tümü'),
            'interests' => array_values(array_unique(array_filter($interests))),
            'audiences' => array_values(array_filter(array_map(fn ($a): string => (string) ($a['name'] ?? ''), (array) ($t['custom_audiences'] ?? [])))),
            'excluded' => array_values(array_filter(array_map(fn ($a): string => (string) ($a['name'] ?? ''), (array) ($t['excluded_custom_audiences'] ?? [])))),
            'placements' => $platforms === [] ? 'Advantage+ yerleşim' : implode(', ', array_map('ucfirst', $platforms)).($positions !== [] ? ' · '.count($positions).' yerleşim' : ''),
            'advantage' => (int) ($t['targeting_automation']['advantage_audience'] ?? 0) === 1,
        ];
    }

    /**
     * "Hizmet ortalaması": each campaign's cost per result beside the median of the other brands for its (first)
     * service and its result type (AdServiceStats, rebuilt daily). Campaigns without a service or a typed result get none.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    private function withAverages(DigitalAsset $asset, array $rows): array
    {
        $brand = $asset->brand;
        $serviceOf = $brand !== null ? array_column($this->services->offerings($brand), 'service_id', 'id') : [];
        $costs = $serviceOf !== [] && Schema::hasTable('ad_service_stats') ? AdServiceStats::brandCosts('meta', array_values(array_unique(array_filter($serviceOf)))) : [];
        $city = $brand !== null && $costs !== [] ? AdServiceStats::city($brand) : '';
        foreach ($rows as $i => $row) {
            $rows[$i]['average'] = null;
            $serviceId = $serviceOf[$row['services'][0]['id'] ?? 0] ?? null;
            if ($serviceId === null || ! in_array($row['type'], ['leads', 'messages', 'purchases'], true)) {
                continue;
            }
            $average = AdServiceStats::average($costs, (int) $serviceId, $row['type'], (int) $asset->brand_id, $city);
            if ($average !== null) {
                $rows[$i]['average'] = $average + ['diff' => MetaScreen::change($row['cpr'], $average['median']), 'verdict' => AdServiceStats::verdict($row['cpr'], $average['median'])];
            }
        }

        return $rows;
    }

    /* ---------------- helpers ---------------- */

    /** @return array{amount: ?float, level: string} daily budget in account currency (the collector already stores major units), campaign or summed ad sets */
    private function budget(array $campaign, array $entities): array
    {
        if ($campaign['daily_budget'] !== null && $campaign['daily_budget'] > 0) {
            return ['amount' => round($campaign['daily_budget'], 2), 'level' => 'campaign'];
        }
        $sum = 0.0;
        foreach ($entities['adsets'] as $adset) {
            if ($adset['campaign_id'] === $campaign['id'] && $adset['daily_budget'] !== null && self::status($adset['status']) === 'live') {
                $sum += $adset['daily_budget'];
            }
        }

        return ['amount' => $sum > 0 ? round($sum, 2) : null, 'level' => 'adset'];
    }

    /**
     * @param  array<string, array<string, float|int>>  $current  campaign rollup
     * @param  array<string, array<string, float|int>>  $previous
     * @param  list<array<string, mixed>>  $rows
     * @return array<string, array<string, mixed>> spend and, per result type, count and cost over the campaigns of that type
     */
    private function kpis(array $current, array $previous, array $rows): array
    {
        $spend = round(array_sum(array_column($current, 'spend')), 2);
        $out = ['spend' => ['value' => $spend, 'change' => MetaScreen::change($spend, round(array_sum(array_column($previous, 'spend')), 2))]];
        foreach (['leads', 'messages', 'purchases'] as $type) {
            $ids = array_column(array_filter($rows, fn (array $r): bool => $r['type'] === $type), 'id');
            $cur = array_intersect_key($current, array_flip($ids));
            $prev = array_intersect_key($previous, array_flip($ids));
            $count = (float) array_sum(array_column($cur, $type));
            $prevCount = (float) array_sum(array_column($prev, $type));
            $cost = $count > 0 ? round(array_sum(array_column($cur, 'spend')) / $count, 2) : null;
            $prevCost = $prevCount > 0 ? round(array_sum(array_column($prev, 'spend')) / $prevCount, 2) : null;
            $out[$type] = ['value' => $count, 'cost' => $cost, 'change' => MetaScreen::change($cost, $prevCost)];
        }

        return $out;
    }

    /** @return array{disapproved: array<string, true>, fatigue: array<string, true>} campaign ids with an ad issue */
    private function adIssues(array $account, array $entities): array
    {
        $out = ['disapproved' => [], 'fatigue' => []];
        foreach ($entities['ads'] as $ad) {
            if (in_array(strtoupper($ad['status']), ['DISAPPROVED', 'WITH_ISSUES'], true)) {
                $out['disapproved'][$ad['campaign_id']] = true;
            }
        }
        foreach (array_keys($this->screen->fatigue($account)) as $adId) {
            if (isset($entities['ads'][$adId]) && self::status($entities['ads'][$adId]['status']) === 'live') {
                $out['fatigue'][$entities['ads'][$adId]['campaign_id']] = true;
            }
        }

        return $out;
    }

    /** @return array<string, mixed> the campaign's whole snapshot */
    private function metadata(array $account, string $campaignId): array
    {
        return MetaScreen::json($this->screen->q($account, 'meta_campaign_snapshot')?->where('campaign_id', $campaignId)->value('metadata'));
    }

    /**
     * Change events of the campaign, its ad sets and ads in the window (newest first).
     *
     * @param  list<string>  $objectIds
     * @return list<array{date: string, label: string, object: string, actor: string}>
     */
    private function events(array $account, array $objectIds, string $from, string $to): array
    {
        return ($this->screen->q($account, 'meta_change_event')?->whereIn('object_id', $objectIds)
            ->whereBetween('event_time', [$from.' 00:00:00', $to.' 23:59:59'])->orderByDesc('event_time')->limit(40)
            ->get(['event_time', 'translated_event_type', 'event_type', 'object_name', 'actor_name']) ?? collect())
            ->map(fn ($e): array => ['date' => substr((string) $e->event_time, 0, 10), 'label' => (string) ($e->translated_event_type ?: $e->event_type),
                'object' => (string) $e->object_name, 'actor' => (string) $e->actor_name])->all();
    }

    private static function date(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? CarbonImmutable::parse($value)->toDateString() : null;
    }

    private static function statusOrder(string $status): int
    {
        return match ($status) {
            'live' => 0,
            'paused' => 1,
            default => 2,
        };
    }
}
