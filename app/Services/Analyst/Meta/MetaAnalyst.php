<?php

namespace App\Services\Analyst\Meta;

use App\Enums\AdvisorCategory;
use App\Enums\AdvisorItemStatus;
use App\Models\AdvisorItem;
use App\Models\AdvisorPlan;
use App\Models\AnalystDecision;
use App\Models\Brand;
use App\Models\DigitalAsset;
use App\Models\User;
use App\Services\Advisor\AdvisorItemActions;
use App\Services\Analyst\AbstractChannelAnalyst;
use App\Services\Analyst\AnalystPack;
use App\Services\Analyst\Contracts\DownloadsDecision;
use App\Support\Options\IndustryOptions;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Meta: the brand's Meta ad accounts analysed like a senior Meta ads consultant (lead generation for clinics).
 * Facts from stored data only (MetaFacts: advisor collector + rules, 28-day windows, regions vs service areas, lead
 * outcomes, compliance). No Meta writes: actions are an AI creative draft (existing advisor draft flow), links
 * (campaign, lead outcomes, measurement) and a downloadable change plan the operator applies in Ads Manager.
 */
final class MetaAnalyst extends AbstractChannelAnalyst implements DownloadsDecision
{
    public const array ACTIONS = [
        'draft_creatives' => ['label' => 'Kreatif taslağı hazırla', 'kind' => 'run', 'targets' => ['ad']],
        'open_campaign' => ['label' => 'Kampanyayı aç', 'kind' => 'link', 'targets' => ['cmp', 'set', 'ad', 'acc']],
        'mark_lead_outcomes' => ['label' => 'Lead sonuçlarını gir', 'kind' => 'link', 'targets' => ['lq', 'leadc']],
        'fix_tracking' => ['label' => 'Ölçümü düzelt', 'kind' => 'link', 'targets' => ['trk', 'px', 'acc']],
        'export_plan' => ['label' => 'Değişiklik planını indir', 'kind' => 'run', 'targets' => ['ad', 'set', 'cmp', 'reg', 'plc', 'demo']],
    ];

    public function __construct(private readonly MetaFacts $facts) {}

    public function channel(): string
    {
        return 'meta';
    }

    public function allowedActions(): array
    {
        return self::ACTIONS;
    }

    public function instructions(): string
    {
        return <<<'TXT'
Channel: Meta ads (Facebook / Instagram) of a health / clinic brand whose business goal is qualified leads
(appointments, calls, messages). Think and write like a senior Meta ads consultant; every number from INPUT_JSON.
Accounts are separate ("acc:"); never add money across accounts with different currencies. MoxDOP never changes
Meta: the operator applies changes in Ads Manager. Work in this priority order:
1) Measurement first: pixel / Conversions API / dataset problems ("trk:", "px:") → fix_tracking; lead outcomes not
   marked ("lq:" unmarked > 0 or no outcomes) → mark_lead_outcomes (without outcome feedback the account optimizes
   for cheap, not qualified leads).
2) Objective / optimization fit: campaigns spending on "üst huni" / "etkileşim" or optimizing for clicks while the
   goal is leads → open_campaign (target the campaign).
3) Budget waste: campaigns / ads with spend and no or very expensive results, low-quality lead labels ("leadc:"
   junk), regions outside the service areas ("reg:" in_area false), fatigued creatives (frequency up, CTR down) →
   export_plan (target the ad / ad set / campaign / region to pause, exclude or move budget from).
4) Learning limited / fragmentation ("set:" learning_limited, many small ad sets in one campaign) → export_plan
   (consolidate) or open_campaign.
5) Creative refresh: fatigued or weak-hook ads (hold25_pct, thruplay_pct, ctr_7d vs ctr_prev_7d) → draft_creatives
   (target the ad); ads breaking sector compliance ("cmpl:") must be replaced first.
6) Placements / audience ("plc:", "demo:") → export_plan.
Health ads (Turkish health promotion regulation + Meta health policy): never propose before/after, testimonials,
discounts / prices, guarantees or "best / number one" claims.
TXT;
    }

    /** "Kampanya" is the Meta campaign object here, not a promotion: it is not checked as an inducement word. */
    protected function complianceText(array $decision): string
    {
        return (string) preg_replace('/\bkampanya\p{L}*/iu', '', $decision['title_tr']);
    }

    public function buildPack(Brand $brand): AnalystPack
    {
        $analysis = $this->facts->analyze($brand);
        $context = [
            'brand' => $brand->name,
            'sector' => implode(', ', array_filter(array_map(fn (string $c): string => (string) IndustryOptions::label($c), $brand->sectorCodes()))),
            'business_goal' => 'lead (randevu / arama / mesaj)',
            'window_days' => MetaFacts::WINDOW_DAYS,
            'service_areas' => $brand->serviceAreas()->where('status', 'active')->get()->map(fn ($a): string => $a->label())->take(15)->values()->all(),
            'currencies' => $analysis['currencies'],
        ];
        $stats = $this->stats($brand);
        $missing = $this->facts->missing($brand);
        if ($missing !== null) {
            return AnalystPack::missing('meta', (int) $brand->id, $missing, $stats, $context);
        }

        $sections = [];
        foreach ($analysis['accounts'] as $assetId => $account) {
            $c = $account['currency'];
            $money = fn (?float $v): string => MetaFacts::money($v, $c);
            $cur = $account['totals']['cur'];
            $prev = $account['totals']['prev'];
            $cpr = $cur['results'] > 0 ? round($cur['conv_spend'] / $cur['results'], 2) : null;
            $cprPrev = $prev['results'] > 0 ? round($prev['conv_spend'] / $prev['results'], 2) : null;
            $sections['accounts']['acc:'.$assetId] = [
                'name' => $account['name'], 'currency' => $c,
                'spend' => round($cur['spend'], 2), 'spend_prev' => round($prev['spend'], 2), 'spend_delta_pct' => MetaFacts::deltaPct($cur['spend'], $prev['spend']),
                'results' => round($cur['results'], 1), 'results_prev' => round($prev['results'], 1), 'results_delta_pct' => MetaFacts::deltaPct($cur['results'], $prev['results']),
                'cpr' => $cpr, 'cpr_prev' => $cprPrev, 'cpr_delta_pct' => $cpr !== null && $cprPrev !== null ? MetaFacts::deltaPct($cpr, $cprPrev) : null,
                'impressions' => $cur['impressions'], 'link_clicks' => $cur['link_clicks'],
                'ctr' => $cur['impressions'] > 0 ? round($cur['link_clicks'] / $cur['impressions'] * 100, 2) : null,
                'cpm' => $cur['impressions'] > 0 ? round($cur['spend'] / $cur['impressions'] * 1000, 2) : null,
                'frequency_7d' => $account['frequency_7d'],
                'roas' => $account['purchase_value'] > 0 && $cur['spend'] > 0 ? round($account['purchase_value'] / $cur['spend'], 2) : null,
                'display' => $money($cur['spend']).' · '.(int) round($cur['results']).' sonuç · '.$money($cpr).'/sonuç',
            ];
            foreach ($account['tracking'] as $issue) {
                $sections['tracking']['trk:'.$assetId.'-'.substr(hash('sha256', $issue['subject'].$issue['issue']), 0, 8)] = [
                    'name' => $issue['subject'], 'account' => $account['name'], 'status' => 'sorun', 'issue' => $issue['issue'], 'note' => $issue['fix'],
                ];
            }
            foreach ($account['sources']['items'] as $source) {
                $days = $source['last_fired_time'] !== null ? (int) floor((strtotime($account['end'].' 23:59:59') - strtotime($source['last_fired_time'])) / 86400) : null;
                $sections['tracking']['px:'.$assetId.'-'.$source['id']] = [
                    'name' => (string) ($source['name'] ?: $source['type'].' '.$source['id']), 'type' => mb_strtolower($source['type']), 'event' => $source['event_type'],
                    'status' => match (true) {
                        $source['is_unavailable'] === true => 'kullanılamaz', $source['is_archived'] === true => 'arşivli',
                        $days === null => 'hiç veri yok', $days > 3 => 'sessiz', default => 'çalışıyor',
                    },
                    'days_silent' => $days, 'last_fired' => $source['last_fired_time'] !== null ? substr($source['last_fired_time'], 0, 10) : null,
                ];
            }
            foreach ($account['campaigns'] as $id => $campaign) {
                if ($campaign['cur']['spend'] <= 0 && $campaign['prev']['spend'] <= 0) {
                    continue;
                }
                $w = $campaign['cur'];
                $ccpr = $w['results'] > 0 ? round($w['spend'] / $w['results'], 2) : null;
                $pcpr = $campaign['prev']['results'] > 0 ? round($campaign['prev']['spend'] / $campaign['prev']['results'], 2) : null;
                $sections['campaigns']['cmp:'.$assetId.'-'.$id] = [
                    'name' => $campaign['name'], 'account' => $account['name'], 'status' => $campaign['status'], 'objective' => $campaign['objective'],
                    'optimization_goal' => $campaign['optimization_goal'], 'result_type' => $campaign['result_type'], 'objective_fit' => $campaign['objective_fit'],
                    'budget' => $campaign['budget'], 'daily_budget' => $campaign['daily_budget'], 'active_adsets' => $campaign['active_adsets'],
                    'spend' => round($w['spend'], 2), 'spend_share_pct' => $cur['spend'] > 0 ? round($w['spend'] / $cur['spend'] * 100, 1) : null,
                    'spend_delta_pct' => MetaFacts::deltaPct($w['spend'], $campaign['prev']['spend']),
                    'results' => round($w['results'], 1), 'cpr' => $ccpr, 'cpr_delta_pct' => $ccpr !== null && $pcpr !== null ? MetaFacts::deltaPct($ccpr, $pcpr) : null,
                    'ctr' => $w['impressions'] > 0 ? round($w['link_clicks'] / $w['impressions'] * 100, 2) : null,
                    'cpm' => $w['impressions'] > 0 ? round($w['spend'] / $w['impressions'] * 1000, 2) : null,
                    'frequency_7d' => $campaign['frequency_7d'],
                    'display' => $money($w['spend']).' · '.(int) round($w['results']).' sonuç · '.$money($ccpr).'/sonuç',
                ];
            }
            foreach ($account['adsets'] as $id => $set) {
                if ($set['spend'] <= 0 && ! $set['learning_limited']) {
                    continue;
                }
                $sections['adsets']['set:'.$assetId.'-'.$id] = [
                    'name' => $set['name'], 'campaign' => $account['campaigns'][$set['campaign_id']]['name'] ?? null, 'status' => $set['learning_limited'] ? 'öğrenme sınırlı' : $set['status'],
                    'optimization_goal' => $set['optimization_goal'], 'daily_budget' => $set['daily_budget'], 'spend' => $set['spend'], 'results' => $set['results'], 'cpr' => $set['cpr'],
                    'spend_7d' => $set['spend_7d'], 'results_7d' => $set['results_7d'], 'learning_limited' => $set['learning_limited'], 'needed_daily_budget' => $set['needed_daily'],
                    'display' => $money($set['spend']).' · 7g '.$set['results_7d'].' sonuç',
                ];
            }
            foreach ($account['regions'] as $region) {
                $sections['regions']['reg:'.$assetId.'-'.MetaFacts::slug($region['region'])] = [
                    'name' => $region['region'], 'area' => $region['region'], 'account' => $account['name'],
                    'status' => $region['in_area'] === null ? null : ($region['in_area'] ? 'hizmet bölgesi' : 'bölge dışı'), 'in_area' => $region['in_area'],
                    'spend' => $region['spend'], 'share_pct' => $region['share'], 'clicks' => $region['clicks'], 'results' => $region['results'], 'cpr' => $region['cpr'],
                    'display' => $money($region['spend']).' (%'.str_replace('.', ',', (string) $region['share']).')'.($region['results'] !== null ? ' · '.$region['results'].' sonuç' : ''),
                ];
            }
            foreach ($account['ads'] as $id => $ad) {
                $sections['ads']['ad:'.$assetId.'-'.$id] = array_filter([
                    'name' => $ad['name'], 'campaign' => $ad['campaign'], 'adset' => $ad['adset'], 'objective' => $ad['objective'], 'status' => $ad['fatigue'] ? 'yoruldu' : $ad['status'],
                    'spend' => $ad['spend'], 'results' => $ad['results'], 'cpr' => $ad['cpr'], 'ctr' => $ad['ctr'], 'ctr_7d' => $ad['ctr_7d'], 'ctr_prev_7d' => $ad['ctr_prev_7d'],
                    'frequency_7d' => $ad['frequency_7d'], 'frequency_prev_7d' => $ad['frequency_prev_7d'], 'fatigue' => $ad['fatigue'],
                    'video_plays' => $ad['video_plays'], 'thruplay_pct' => $ad['thruplay_pct'], 'hold25_pct' => $ad['hold25_pct'],
                    'title' => $ad['title'], 'body' => $ad['body'], 'cta' => $ad['cta'], 'link' => $ad['link'],
                    'display' => $money($ad['spend']).' · CTR %'.str_replace('.', ',', (string) ($ad['ctr_7d'] ?? $ad['ctr'] ?? 0)).' · sıklık '.str_replace('.', ',', (string) ($ad['frequency_7d'] ?? '—')),
                ], fn ($v): bool => $v !== null);
                if ($ad['compliance'] !== []) {
                    $sections['compliance']['cmpl:'.$assetId.'-'.$id] = ['name' => $ad['name'], 'status' => 'kurala aykırı', 'rule' => implode('; ', array_slice($ad['compliance'], 0, 3)), 'spend' => $ad['spend']];
                }
            }
            foreach ($account['advisor'] as $item) {
                $suffix = $item['rule_id'] === 'creative-fatigue' ? '-'.($item['evidence']['ad_id'] ?? '') : '';
                $sections['advisor']['adv:'.$assetId.'-'.$item['rule_id'].$suffix] = [
                    'name' => $item['title'], 'rule' => $item['rule_id'], 'status' => $item['severity'], 'display' => $item['impact_label'], 'impact' => $item['impact_amount'],
                    'note' => mb_substr((string) ($item['checklist'][0] ?? ''), 0, 160),
                ];
            }
            foreach ($account['placements'] as $placement) {
                $sections['placements']['plc:'.$assetId.'-'.MetaFacts::slug($placement['label'])] = [
                    'name' => $placement['label'], 'spend' => $placement['spend'], 'share_pct' => $placement['share'], 'ctr' => $placement['ctr'], 'cpc' => $placement['cpc'],
                    'display' => $money($placement['spend']).' · CTR %'.str_replace('.', ',', (string) ($placement['ctr'] ?? 0)),
                ];
            }
            foreach ($account['demographics'] as $key => $row) {
                $sections['audience']['demo:'.$assetId.'-'.MetaFacts::slug($key)] = [
                    'name' => $row['label'], 'spend' => $row['spend'], 'share_pct' => $row['share'], 'ctr' => $row['ctr'],
                    'display' => $money($row['spend']).' (%'.str_replace('.', ',', (string) $row['share']).')',
                ];
            }
        }
        $leads = $analysis['leads'];
        $lq = $leads['meta'];
        $currency = count($analysis['currencies']) === 1 ? $analysis['currencies'][0] : null;
        $sections['lead_quality']['lq:meta'] = [
            'name' => 'Meta form leadleri', 'total' => $lq['total'], 'marked' => $lq['marked'], 'unmarked' => $lq['unmarked'], 'qualified' => $lq['qualified'], 'junk' => $lq['junk'],
            'qualified_rate' => $lq['qualified_rate'], 'cost_per_lead' => $lq['cost_per_lead'], 'cost_per_qualified' => $lq['cost_per_qualified'],
            'status' => $lq['total'] === 0 ? 'lead sonucu girilmedi' : ($lq['unmarked'] > 0 ? $lq['unmarked'].' işaretsiz' : 'işaretli'),
            'display' => $lq['qualified_rate'] !== null ? '%'.str_replace('.', ',', (string) $lq['qualified_rate']).' kaliteli · '.MetaFacts::money($lq['cost_per_qualified'], $currency).'/kaliteli' : '—',
        ];
        $all = $leads['all'];
        $sections['lead_quality']['lq:all'] = ['name' => 'Tüm kaynaklar', 'total' => $all['total'], 'marked' => $all['marked'], 'unmarked' => $all['unmarked'],
            'qualified' => $all['qualified'], 'junk' => $all['junk'], 'qualified_rate' => $all['qualified_rate']];
        foreach ($leads['by_label'] as $label => $row) {
            $sections['lead_quality']['leadc:'.MetaFacts::slug((string) $label)] = ['name' => (string) $label] + $row
                + ['note' => $row['marked'] > 0 ? $row['junk'].'/'.$row['marked'].' geçersiz' : null];
        }

        return (new AnalystPack('meta', (int) $brand->id, $context, $stats, $sections))->trimTo();
    }

    /**
     * Durum: harcama, sonuç (+ sonuç başı maliyet), kaliteli lead, sıklık, piksel / CAPI, öğrenmede takılı.
     *
     * @return list<array<string, mixed>>
     */
    public function stats(Brand $brand): array
    {
        $analysis = $this->facts->analyze($brand);
        $accounts = $analysis['accounts'];
        if ($accounts === []) {
            return [];
        }
        $single = count($analysis['currencies']) <= 1;
        $currency = $analysis['currencies'][0] ?? null;
        $sum = fn (string $w, string $k): float => array_sum(array_map(fn (array $a): float => (float) $a['totals'][$w][$k], $accounts));
        $spend = $sum('cur', 'spend');
        $spendPrev = $sum('prev', 'spend');
        $results = $sum('cur', 'results');
        $resultsPrev = $sum('prev', 'results');
        $cpr = $results > 0 ? round($sum('cur', 'conv_spend') / $results, 2) : null;
        $cprPrev = $resultsPrev > 0 ? round($sum('prev', 'conv_spend') / $resultsPrev, 2) : null;
        $cprDelta = $cpr !== null && $cprPrev !== null ? MetaFacts::deltaPct($cpr, $cprPrev) : null;
        $freqW = 0.0;
        $freqN = 0;
        foreach ($accounts as $account) {
            if ($account['frequency_7d'] !== null) {
                $freqW += $account['frequency_7d'] * max(1, $account['totals']['cur']['impressions']);
                $freqN += max(1, $account['totals']['cur']['impressions']);
            }
        }
        $frequency = $freqN > 0 ? round($freqW / $freqN, 2) : null;
        $issues = array_sum(array_map(fn (array $a): int => count($a['tracking']), $accounts));
        $sourcesKnown = collect($accounts)->contains(fn (array $a): bool => $a['sources']['available']);
        $learning = 0;
        $activeSets = 0;
        foreach ($accounts as $account) {
            foreach ($account['adsets'] as $set) {
                $learning += $set['learning_limited'] ? 1 : 0;
                $activeSets += $set['status'] === 'aktif' ? 1 : 0;
            }
        }
        $lq = $analysis['leads']['meta']['marked'] > 0 ? $analysis['leads']['meta'] : $analysis['leads']['all'];
        $lqNote = $lq['marked'] > 0 ? $lq['qualified'].'/'.$lq['marked'].' işaretli'.($lq === $analysis['leads']['meta'] && $lq['cost_per_qualified'] !== null ? ' · '.MetaFacts::money($lq['cost_per_qualified'], $currency).'/kaliteli' : '') : 'sonuç girilmedi';
        $fmt = fn (?float $v): string => $v === null ? '—' : str_replace('.', ',', (string) $v);

        return [
            ['id' => 'spend_28d', 'label' => 'Harcama (28g)', 'value' => $single ? round($spend, 2) : null, 'display' => $single ? MetaFacts::money($spend, $currency) : 'Hesap bazında',
                'delta_pct' => $single ? MetaFacts::deltaPct($spend, $spendPrev) : null, 'previous' => $single ? round($spendPrev, 2) : null,
                'note' => count($accounts) > 1 ? count($accounts).' hesap' : null],
            ['id' => 'results_28d', 'label' => 'Sonuç (28g)', 'value' => round($results, 1), 'display' => number_format($results, 0, ',', '.'),
                'delta_pct' => MetaFacts::deltaPct($results, $resultsPrev), 'previous' => round($resultsPrev, 1),
                'cpr' => $single ? $cpr : null, 'cpr_previous' => $single ? $cprPrev : null, 'cpr_delta_pct' => $single ? $cprDelta : null,
                'note' => $single && $cpr !== null ? MetaFacts::money($cpr, $currency).'/sonuç'.($cprDelta !== null ? ' ('.($cprDelta >= 0 ? '+' : '-').'%'.abs($cprDelta).')' : '') : null],
            ['id' => 'quality_lead_rate', 'label' => 'Kaliteli lead', 'value' => $lq['qualified_rate'], 'display' => $lq['qualified_rate'] === null ? '—' : '%'.$fmt($lq['qualified_rate']),
                'note' => $lqNote, 'qualified' => $lq['qualified'], 'marked' => $lq['marked'], 'unmarked' => $lq['unmarked']],
            ['id' => 'frequency_7d', 'label' => 'Sıklık (7g)', 'value' => $frequency, 'display' => $fmt($frequency)],
            ['id' => 'tracking', 'label' => 'Piksel / CAPI', 'value' => $issues, 'display' => $issues > 0 ? $issues.' sorun' : ($sourcesKnown ? 'Sağlıklı' : 'Veri yok')],
            ['id' => 'learning_limited', 'label' => 'Öğrenmede takılı', 'value' => $learning, 'display' => (string) $learning, 'note' => $activeSets > 0 ? '/'.$activeSets.' aktif set' : null],
        ];
    }

    public function presentAction(AnalystDecision $decision): ?array
    {
        $spec = self::ACTIONS[$decision->action_type] ?? null;
        if ($spec === null) {
            return null;
        }
        [$prefix, $assetId, $entityId] = self::parseTarget((string) ($decision->action_params['target'] ?? ''));
        $asset = $assetId !== null ? DigitalAsset::query()->where('brand_id', $decision->brand_id)->find($assetId) : null;
        $meta = fn (array $params): ?string => $asset === null ? null : route('operator.meta.overview', ['assetId' => $asset->id] + $params);
        $url = match ($decision->action_type) {
            'open_campaign' => match ($prefix) {
                'cmp' => $meta(['tab' => 'campaigns', 'campaign' => $entityId]),
                'set' => $meta(['tab' => 'campaigns', 'level' => 'adsets', 'adset' => $entityId]),
                'ad' => $meta(['tab' => 'campaigns', 'level' => 'ads']),
                default => $meta(['tab' => 'campaigns']),
            },
            'fix_tracking' => $meta(['tab' => 'measurement']),
            'mark_lead_outcomes' => route('operator.brand.leads', ['brand' => $decision->brand_id]),
            default => null,
        };

        return ['label' => $spec['label'], 'kind' => $spec['kind'], 'url' => $spec['kind'] === 'link' ? $url : null];
    }

    public function perform(AnalystDecision $decision, User $user): string
    {
        return match ($decision->action_type) {
            'draft_creatives' => $this->draftCreatives($decision),
            'export_plan' => 'Değişiklik planı hazır: '.count($this->planRows(Brand::query()->findOrFail($decision->brand_id))).' satır; Meta sekmesinde "Değişiklik planını indir" ile indir ve Ads Manager\'da uygula.',
            default => throw ValidationException::withMessages(['analyst' => 'Bu kart için çalıştırılacak bir işlem yok.']),
        };
    }

    public function download(AnalystDecision $decision, User $user): ?StreamedResponse
    {
        if ($decision->action_type !== 'export_plan') {
            return null;
        }
        $brand = Brand::query()->findOrFail($decision->brand_id);

        return app(MetaPlanExport::class)->download($brand, $this->planRows($brand));
    }

    public function baseline(AnalystDecision $decision): array
    {
        $brand = Brand::query()->find($decision->brand_id);
        if ($brand === null) {
            return [];
        }
        $stats = collect($this->stats($brand))->keyBy('id');

        return array_filter([
            'meta_spend_28d' => $stats['spend_28d']['value'] ?? null,
            'meta_results_28d' => $stats['results_28d']['value'] ?? null,
            'meta_cpr_28d' => $stats['results_28d']['cpr'] ?? null,
            'meta_quality_lead_rate' => $stats['quality_lead_rate']['value'] ?? null,
        ], fn ($v): bool => $v !== null);
    }

    /**
     * The open Meta cards as plan rows (what to change where), most urgent first.
     *
     * @return list<array<string, string>>
     */
    public function planRows(Brand $brand): array
    {
        $levels = ['acc' => 'Hesap', 'cmp' => 'Kampanya', 'set' => 'Reklam seti', 'ad' => 'Reklam', 'reg' => 'Bölge', 'plc' => 'Yerleşim', 'demo' => 'Kitle',
            'trk' => 'Ölçüm', 'px' => 'Ölçüm', 'lq' => 'Lead sonucu', 'leadc' => 'Lead sonucu'];
        $accounts = DigitalAsset::query()->where('brand_id', $brand->id)->where('type', 'meta_ads')->pluck('name', 'id');
        $rows = [];
        foreach (AnalystDecision::query()->where('brand_id', $brand->id)->where('channel', 'meta')->actionable()->orderBy('priority')->orderBy('id')->get() as $decision) {
            $target = (string) ($decision->action_params['target'] ?? '');
            $fact = (array) ($decision->action_params['_target'] ?? []);
            [$prefix, $assetId, $entityId] = self::parseTarget($target);
            $rows[] = [
                'Öncelik' => (string) $decision->priority,
                'Hesap' => $assetId !== null ? (string) ($accounts[$assetId] ?? $fact['account'] ?? '') : '',
                'Düzey' => $levels[$prefix] ?? '',
                'Ad' => (string) ($fact['name'] ?? ''),
                'Kimlik' => in_array($prefix, ['cmp', 'set', 'ad'], true) ? (string) $entityId : '',
                'Yapılacak' => (string) $decision->title,
                'Neden' => (string) $decision->why,
                'Veri' => (string) ($fact['display'] ?? ''),
                'Kaynak' => self::ACTIONS[$decision->action_type]['label'] ?? $decision->action_type,
            ];
        }

        return $rows;
    }

    /** @return array{0: string, 1: int|null, 2: string|null} prefix, asset id, entity id of "cmp:12-6789" */
    public static function parseTarget(string $target): array
    {
        [$prefix, $rest] = array_pad(explode(':', $target, 2), 2, '');
        if (preg_match('/^(\d+)(?:-(.+))?$/', $rest, $m) === 1) {
            return [$prefix, (int) $m[1], $m[2] ?? null];
        }

        return [$prefix, null, $rest !== '' ? $rest : null];
    }

    /**
     * Existing advisor draft flow: the ad's creative-fatigue advisor item (created from the card's facts when the
     * weekly advisor has not produced one) → AdvisorItemActions::requestDraft → DraftMetaAdsCreativeJob (1 AI call,
     * sector compliance rules in the prompt; the operator builds the new ads in Meta).
     */
    private function draftCreatives(AnalystDecision $decision): string
    {
        [$prefix, $assetId, $adId] = self::parseTarget((string) ($decision->action_params['target'] ?? ''));
        $asset = $prefix === 'ad' && $assetId !== null ? DigitalAsset::query()->where('brand_id', $decision->brand_id)->where('type', 'meta_ads')->find($assetId) : null;
        if ($asset === null || $adId === null) {
            throw ValidationException::withMessages(['analyst' => 'Reklam artık bu markada yok; yeniden analiz edin.']);
        }
        $fact = (array) ($decision->action_params['_target'] ?? []);
        $key = hash('sha256', 'meta_ads|creative-fatigue|'.$adId);
        $item = AdvisorItem::query()->where('digital_asset_id', $asset->id)->where('channel', AdvisorPlan::CHANNEL_META_ADS)->where('item_key', $key)->first();
        if ($item === null) {
            $asset->loadMissing('brand');
            $plan = AdvisorPlan::query()->create([
                'channel' => AdvisorPlan::CHANNEL_META_ADS, 'customer_id' => $asset->brand?->customer_id, 'brand_id' => $asset->brand_id, 'digital_asset_id' => $asset->id,
                'status' => AdvisorPlan::STATUS_COMPLETED, 'trigger' => 'analyst', 'completed_at' => now(),
                'version' => (int) AdvisorPlan::query()->where('digital_asset_id', $asset->id)->where('channel', AdvisorPlan::CHANNEL_META_ADS)->max('version') + 1,
                'summary_text' => 'Meta analisti: kreatif taslağı isteği',
            ]);
            $item = AdvisorItem::query()->create([
                'channel' => AdvisorPlan::CHANNEL_META_ADS, 'customer_id' => $plan->customer_id, 'brand_id' => $plan->brand_id, 'digital_asset_id' => $asset->id,
                'item_key' => $key, 'category' => AdvisorCategory::Ads->value, 'rule_id' => 'creative-fatigue', 'severity' => 'medium', 'priority_score' => 400,
                'impact_amount' => isset($fact['spend']) ? (float) $fact['spend'] : null, 'impact_label' => 'Kreatif yenileme', 'currency' => null,
                'title' => mb_substr('Kreatif yenile: '.($fact['name'] ?? $adId), 0, 255), 'reason' => (string) $decision->why,
                'evidence' => [
                    'ad' => $fact['name'] ?? null, 'ad_id' => $adId, 'campaign' => $fact['campaign'] ?? null, 'objective' => $fact['objective'] ?? null,
                    'weeks' => [['period' => 'Son 28 gün', 'cost' => $fact['spend'] ?? null, 'ctr' => $fact['ctr'] ?? null, 'frequency' => $fact['frequency_7d'] ?? null,
                        'conversions' => $fact['results'] ?? null, 'cpa' => $fact['cpr'] ?? null]],
                    'creative_title' => $fact['title'] ?? null, 'creative_body' => $fact['body'] ?? null, 'creative_cta' => $fact['cta'] ?? null, 'final_url' => $fact['link'] ?? null,
                ],
                'checklist' => ['Aynı reklam setine 2–3 yeni kreatif ekle (farklı görsel/video ve açılış).', 'Yenileri teslimat alınca eskisini durdur.'],
                'status' => AdvisorItemStatus::Open->value, 'first_seen_plan_id' => $plan->id, 'last_seen_plan_id' => $plan->id,
            ]);
        }

        return app(AdvisorItemActions::class)->requestDraft($item) ?? 'Kreatif taslağı zaten hazırlanıyor; hazır olunca Meta hesabı › Danışman\'da.';
    }
}
