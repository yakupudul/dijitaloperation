<?php

namespace App\Services\Analyst\GoogleAds;

use App\Models\AdvisorItem;
use App\Models\AnalystDecision;
use App\Models\Brand;
use App\Models\User;
use App\Services\Advisor\AdvisorItemActions;
use App\Services\Advisor\GoogleAds\GoogleAdsEditorExport;
use App\Services\Analyst\AbstractChannelAnalyst;
use App\Services\Analyst\AnalystPack;
use App\Services\Compliance\SectorPackRegistry;
use App\Services\ContentStudio\BriefCompliance;
use App\Services\SeoTasks\SeoText;
use App\Support\Options\IndustryOptions;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Google Ads: analyse every Ads account of the brand the way a senior Google Ads consultant would and steer the week's
 * work. Inputs (stored data only, per account — currencies are never added together): 28 days against the 28 before
 * (campaigns, bidding, budgets, impression share lost to budget / rank), conversion tracking health, search terms with
 * the query pipeline's competitor / banned / irrelevant lists (wasted spend → negatives), converting terms that are
 * not keywords, keywords with Quality Score, RSA strength, landing pages joined with the website's URL verdicts,
 * device / province / hour segments against the brand's service areas, auction insights and the open advisor findings.
 *
 * Actions stay inside ADR-064: the shared negative list (Admin-approved, undoable), a Google Ads Editor file the
 * operator imports himself, AI ad copy drafts and links. No campaign, budget or bid writes.
 */
final class GoogleAdsAnalyst extends AbstractChannelAnalyst
{
    public const array ACTIONS = [
        'fix_tracking' => ['label' => 'Takibi düzelt', 'kind' => 'link', 'targets' => ['trk', 'conv']],
        'add_negatives' => ['label' => 'Negatifleri gözden geçir', 'kind' => 'link', 'targets' => ['acct']],
        'export_editor' => ['label' => 'Editor dosyası indir', 'kind' => 'link', 'targets' => ['acct']],
        'draft_ad_copy' => ['label' => 'Reklam metni taslağı', 'kind' => 'run', 'targets' => ['ad']],
        'open_campaign' => ['label' => 'Kampanyayı aç', 'kind' => 'link', 'targets' => ['camp', 'acct']],
        'review_landing_page' => ['label' => 'Sayfayı incele', 'kind' => 'link', 'targets' => ['lp']],
    ];

    /** Pipeline list labels in the pack. */
    private const array KIND_LABELS = ['competitor' => 'rakip marka', 'banned' => 'yasaklı', 'irrelevant' => 'alakasız', 'brand' => 'kendi marka', 'core' => 'hizmet'];

    public function __construct(private readonly GoogleAdsFacts $facts) {}

    public function channel(): string
    {
        return 'google_ads';
    }

    public function allowedActions(): array
    {
        return self::ACTIONS;
    }

    public function instructions(): string
    {
        return <<<'TXT'
Channel: Google Ads (Search, Performance Max, Display, Video). Think like a senior Google Ads consultant auditing the
account(s) for a busy agency operator: concrete, numbers from INPUT_JSON, one entity per card. A brand may have several
Ads accounts (facts carry the account id in their id, e.g. "camp:12:345"; each account has its own currency — never
add or compare money across currencies). Work in this order of priority:
1) Conversion tracking correctness ("tracking" facts; context.tracking_broken). If tracking is broken, the FIRST card
   is fix_tracking (target: the "trk:" id) with priority 1, and every other card must say in its why that results
   depend on fixing tracking; do not propose bidding changes while tracking is broken.
2) Wasted spend via search terms: non-converting terms on the query pipeline's lists (list = "rakip marka",
   "yasaklı", "alakasız") are the best negative candidates → add_negatives (target: the "acct:" id, evidence_refs:
   the "st:" ids of THAT account). Here — unlike organic search — competitor / irrelevant terms are NOT skipped: they
   are negatives unless competitor conquesting converts. Terms naming the brand's own service that do not convert are
   a landing page / ad problem, not a negative.
3) Impression share lost to budget (lost_is_budget) on campaigns with good CPA/ROAS vs lost to rank (lost_is_rank →
   Quality Score, ad relevance, landing page). No budget or bid writes exist: advise with open_campaign.
4) Bidding strategy fit (conversion volume vs smart bidding, targets vs actual CPA/ROAS) → open_campaign.
5) Ad strength / assets: POOR or AVERAGE RSAs with spend → draft_ad_copy (target: the "ad:" id; only when the fact
   has advisor_item_id); converting terms that are not keywords ("kwo:") and negatives → export_editor (target: the
   "acct:" id, evidence_refs: the "kwo:" / "st:" / "ad:" ids) so the operator imports them in Google Ads Editor.
6) Landing page fit (spend without conversions, URL verdict, errors, speed) → review_landing_page (target "lp:").
7) Geo / device / hour segments (provinces outside the brand's service areas = in_service_area false), competitor
   brand bidding, Performance Max search terms (pmax true) → open_campaign.
Health sector: ads must follow Google's healthcare policy and Turkish health promotion rules (no guarantees, no
before/after promises, no price bait, no "en iyi" superlatives); context.ad_rules lists the brand's sector rules.
TXT;
    }

    public function buildPack(Brand $brand): AnalystPack
    {
        $this->facts->forget($brand);
        $context = $this->context($brand);
        $missing = $this->facts->missing($brand);
        if ($missing !== null) {
            return AnalystPack::missing('google_ads', (int) $brand->id, $missing, [], $context);
        }
        $accounts = $this->facts->accounts($brand);
        $stats = $this->stats($brand);
        $sections = [];
        $broken = false;
        foreach ($accounts as $account) {
            $a = $account['asset_id'];
            foreach ($account['tracking'] as $code => $issue) {
                $ref = ($issue['scope'] ?? 'account') === 'site' ? 'trk:site:'.substr($code, 5) : 'trk:'.$a.':'.$code;
                $broken = $broken || in_array($issue['severity'], ['critical', 'high'], true);
                $sections['tracking'][$ref] = ['account' => $account['name'], 'issue' => $issue['issue'], 'severity' => $issue['severity'], 'note' => $issue['note']]
                    + (array) ($issue['numbers'] ?? []) + (isset($issue['site_id']) ? ['site_id' => $issue['site_id']] : []);
            }
        }
        foreach ($accounts as $account) {
            $a = $account['asset_id'];
            $sections['accounts']['acct:'.$a] = $this->accountFact($account);
            foreach (array_slice($account['campaigns'], 0, 20, true) as $id => $campaign) {
                $sections['campaigns']['camp:'.$a.':'.$id] = ['account' => $a] + array_diff_key($campaign, ['id' => true]);
            }
            foreach (array_slice((array) ($account['input']['conversion_actions']['items'] ?? []), 0, 10) as $action) {
                $sections['conversion_actions']['conv:'.$a.':'.$action['id']] = ['account' => $a, 'name' => mb_substr((string) $action['name'], 0, 60), 'category' => $action['category'],
                    'primary' => (bool) $action['primary'], 'counting' => $action['counting_type'], 'status' => $action['status'],
                    'conversions' => $action['conversions'] !== null ? round((float) $action['conversions'], 1) : null];
            }
            $wasted = array_slice(array_values(array_filter($account['terms'], fn (array $t): bool => $t['wasted'])), 0, 30);
            $other = array_slice(array_values(array_filter($account['terms'], fn (array $t): bool => ! $t['wasted'] && $t['conversions'] <= 0 && $t['cost'] > 0)), 0, 12);
            foreach (array_merge($wasted, $other) as $term) {
                $sections['search_terms'][GoogleAdsFacts::termRef($a, $term['text'])] = [
                    'account' => $a, 'text' => $term['text'], 'list' => self::KIND_LABELS[$term['kind']] ?? null, 'cost' => $term['cost'], 'clicks' => $term['clicks'],
                    'conversions' => $term['conversions'], 'campaigns' => array_slice($term['campaigns'], 0, 3), 'pmax' => $term['pmax'], 'excluded' => $term['excluded'],
                ];
            }
            foreach ($this->facts->keywordOpportunities($account) as $term) {
                $sections['keyword_opportunities']['kwo:'.$a.':'.substr(sha1(mb_strtolower($term['text'])), 0, 10)] = ['account' => $a] + $term;
            }
            $keywords = (array) ($account['input']['keywords'] ?? []);
            usort($keywords, fn (array $x, array $y): int => $y['cost'] <=> $x['cost']);
            foreach (array_slice(array_filter($keywords, fn (array $k): bool => $k['cost'] > 0 || $k['quality_score'] !== null), 0, 25) as $keyword) {
                $sections['keywords']['kw:'.$a.':'.$keyword['ad_group_id'].':'.$keyword['criterion_id']] = [
                    'account' => $a, 'text' => $keyword['text'], 'match' => $keyword['match_type'], 'status' => $keyword['status'], 'quality_score' => $keyword['quality_score'],
                    'weak' => array_values(array_filter(['ad_relevance', 'landing_page_experience', 'expected_ctr'], fn (string $c): bool => strtoupper((string) $keyword[$c]) === 'BELOW_AVERAGE')),
                    'cost' => round((float) $keyword['cost'], 2), 'clicks' => $keyword['clicks'], 'conversions' => round((float) $keyword['conversions'], 1),
                ];
            }
            foreach ($this->facts->adGroups($account) as $group) {
                $sections['ads']['ad:'.$a.':'.$group['ad_group_id']] = [
                    'account' => $a, 'name' => $group['name'], 'campaign' => $group['campaign'], 'ad_strength' => $group['ad_strength'], 'ads' => $group['ads'],
                    'cost' => round($group['cost'], 2), 'final_url' => $group['final_url'], 'advisor_item_id' => $group['advisor_item_id'], 'draft_ready' => $group['draft'] !== null,
                ];
            }
            $assets = (array) ($account['input']['asset_library']['counts'] ?? []);
            if ($assets !== []) {
                $sections['accounts']['acct:'.$a] += ['sitelinks' => (int) ($assets['SITELINK'] ?? 0), 'callouts' => (int) ($assets['CALLOUT'] ?? 0), 'snippets' => (int) ($assets['STRUCTURED_SNIPPET'] ?? 0)];
            }
            foreach ($this->facts->landingPages($brand, $account) as $page) {
                $sections['landing_pages']['lp:'.$a.':'.substr(sha1(SeoText::urlKey($page['url'])), 0, 10)] = ['account' => $a] + array_diff_key($page, ['url' => true]);
            }
            foreach ($this->facts->segments($brand, $account) as $segment) {
                $sections['segments']['seg:'.$a.':'.Str::slug($segment['dimension'].'-'.$segment['label'])] = ['account' => $a] + $segment;
            }
            foreach ($this->facts->auction($a) as $row) {
                $sections['auction_insights']['auc:'.$a.':'.Str::slug($row['domain'])] = ['account' => $a] + $row;
            }
            foreach ($this->facts->advisorItems($a) as $item) {
                $sections['advisor']['adv:'.$item->id] = ['account' => $a, 'rule' => $item->rule_id, 'title' => mb_substr((string) $item->title, 0, 120),
                    'severity' => $item->severity, 'impact' => $item->impact_label, 'currency' => $item->currency];
            }
        }
        foreach ($brand->serviceAreas()->where('status', 'active')->orderBy('priority_rank')->limit(20)->get() as $area) {
            $sections['areas']['area:'.$area->id] = ['name' => $area->label()];
        }
        $context['tracking_broken'] = $broken;

        return (new AnalystPack('google_ads', (int) $brand->id, $context, $stats, $sections))->trimTo();
    }

    /**
     * Durum (6 numbers). Money is summed only when every account has the same currency; otherwise shown per currency.
     *
     * @return list<array<string, mixed>>
     */
    public function stats(Brand $brand): array
    {
        $accounts = $this->facts->accounts($brand);
        if ($accounts === []) {
            return [];
        }
        $currencies = [];
        foreach ($accounts as $account) {
            $c = $account['currency'];
            $currencies[$c] ??= ['cost' => 0.0, 'cost_prev' => 0.0, 'conversions' => 0.0, 'conversions_prev' => 0.0, 'value' => 0.0, 'value_prev' => 0.0, 'wasted' => 0.0];
            foreach (array_keys($currencies[$c]) as $key) {
                $currencies[$c][$key] += (float) $account[$key];
            }
        }
        $single = count($currencies) === 1 ? reset($currencies) : null;
        $currency = $single !== null ? (string) array_key_first($currencies) : null;
        $perCurrency = fn (string $key): string => implode(' · ', array_map(fn (string $c): string => GoogleAdsFacts::money($currencies[$c][$key], $c), array_keys($currencies)));
        $conversions = round(array_sum(array_column($accounts, 'conversions')), 1);
        $conversionsPrev = round(array_sum(array_column($accounts, 'conversions_prev')), 1);
        $accountsNote = count($accounts) > 1 ? count($accounts).' hesap' : null;

        $stats = [
            ['id' => 'spend_28d', 'label' => 'Harcama (28g)', 'value' => $single !== null ? round($single['cost'], 2) : null, 'display' => $perCurrency('cost'),
                'delta_pct' => $single !== null ? GoogleAdsFacts::delta($single['cost'], $single['cost_prev']) : null, 'previous' => $single !== null ? round($single['cost_prev'], 2) : null,
                'note' => $accountsNote, 'currency' => $currency],
            ['id' => 'conversions_28d', 'label' => 'Dönüşüm (28g)', 'value' => $conversions, 'display' => self::number($conversions),
                'delta_pct' => GoogleAdsFacts::delta($conversions, $conversionsPrev), 'previous' => $conversionsPrev],
        ];
        if ($single !== null && $single['value'] > 0 && $single['cost'] > 0) {
            $roas = round($single['value'] / $single['cost'], 2);
            $roasPrev = $single['cost_prev'] > 0 && $single['value_prev'] > 0 ? round($single['value_prev'] / $single['cost_prev'], 2) : null;
            $stats[] = ['id' => 'roas_28d', 'label' => 'ROAS (28g)', 'value' => $roas, 'display' => str_replace('.', ',', (string) $roas),
                'delta_pct' => $roasPrev !== null ? GoogleAdsFacts::delta($roas, $roasPrev) : null, 'previous' => $roasPrev];
        } else {
            $cpa = $single !== null && $single['conversions'] > 0 ? round($single['cost'] / $single['conversions'], 2) : null;
            $cpaPrev = $single !== null && $single['conversions_prev'] > 0 ? round($single['cost_prev'] / $single['conversions_prev'], 2) : null;
            $stats[] = ['id' => 'cpa_28d', 'label' => 'CPA (28g)', 'value' => $cpa, 'display' => $cpa !== null ? GoogleAdsFacts::money($cpa, (string) $currency) : '—',
                'delta_pct' => $cpa !== null && $cpaPrev !== null ? GoogleAdsFacts::delta($cpa, $cpaPrev) : null, 'previous' => $cpaPrev,
                'note' => $single === null ? 'hesap bazında' : null];
        }
        $share = $single !== null && $single['cost'] > 0 ? (int) round($single['wasted'] / $single['cost'] * 100) : null;
        $stats[] = ['id' => 'wasted_spend_28d', 'label' => 'Boşa harcama (28g)', 'value' => $single !== null ? round($single['wasted'], 2) : null, 'display' => $perCurrency('wasted'),
            'note' => $share !== null ? '%'.$share.' harcamanın' : 'alakasız / rakip terim', 'share_pct' => $share];
        $n = array_sum(array_column($accounts, 'l_n'));
        $budget = $n > 0 ? (int) round(array_sum(array_column($accounts, 'lb_w')) / $n * 100) : null;
        $rank = $n > 0 ? (int) round(array_sum(array_column($accounts, 'lr_w')) / $n * 100) : null;
        $stats[] = ['id' => 'lost_impression_share', 'label' => 'Kayıp gösterim payı', 'value' => $budget, 'display' => $budget !== null ? '%'.$budget.' / %'.$rank : '—',
            'note' => $budget !== null ? 'bütçe / sıralama' : 'veri yok', 'budget' => $budget, 'rank' => $rank];
        $issues = $this->trackingIssues($accounts);
        $broken = array_filter($issues, fn (array $i): bool => in_array($i['severity'], ['critical', 'high'], true));
        $stats[] = ['id' => 'tracking', 'label' => 'Dönüşüm takibi', 'value' => count($issues), 'display' => $issues === [] ? 'OK' : 'Sorun',
            'note' => $issues === [] ? null : mb_substr((string) (reset($broken) ?: reset($issues))['issue'], 0, 40), 'broken' => $broken !== []];

        return $stats;
    }

    /**
     * Shared validation, then the tracking rule: when tracking is broken the fix_tracking card is first (added by rule
     * code if the AI left it out) and every other card comes after it.
     */
    public function validate(array $decisions, AnalystPack $pack): array
    {
        $result = parent::validate($decisions, $pack);
        if (! ($pack->context['tracking_broken'] ?? false)) {
            return $result;
        }
        $kept = $result['kept'];
        if (! collect($kept)->contains(fn (array $d): bool => $d['action']['type'] === 'fix_tracking')) {
            $synthetic = $this->trackingDecision($pack);
            if ($synthetic !== null) {
                $check = parent::validate([$synthetic], $pack);
                array_unshift($kept, ...$check['kept']);
            }
        }
        foreach ($kept as $i => $decision) {
            $kept[$i]['priority'] = $decision['action']['type'] === 'fix_tracking' ? 1 : max(2, (int) $decision['priority']);
        }
        usort($kept, fn (array $a, array $b): int => ($a['action']['type'] === 'fix_tracking' ? 0 : 1) <=> ($b['action']['type'] === 'fix_tracking' ? 0 : 1));

        return ['kept' => array_values($kept), 'dropped' => $result['dropped']];
    }

    protected function extraCheck(array $decision, AnalystPack $pack): ?string
    {
        $type = $decision['action']['type'];
        $target = (string) ($decision['action']['params']['target'] ?? '');
        $account = self::accountOf($target);
        $refs = $decision['evidence_refs'];
        $ofAccount = fn (string $prefix): array => array_values(array_filter($refs, fn (string $r): bool => str_starts_with($r, $prefix.':'.$account.':')));

        return match ($type) {
            'add_negatives' => array_filter($ofAccount('st'), fn (string $r): bool => (float) ($pack->fact($r)['conversions'] ?? 1) <= 0 && ! ($pack->fact($r)['excluded'] ?? false)) === []
                && array_filter($refs, fn (string $r): bool => str_starts_with($r, 'adv:') && in_array($pack->fact($r)['rule'] ?? '', ['negative-keywords', 'ngram-waste'], true) && (string) ($pack->fact($r)['account'] ?? '') === $account) === []
                    ? 'negatif aday kanıtı yok' : null,
            'export_editor' => array_merge($ofAccount('st'), $ofAccount('kwo'), $ofAccount('ad')) === [] ? 'Editor dosyasına girecek satır yok' : null,
            'draft_ad_copy' => empty($pack->fact($target)['advisor_item_id']) ? 'bu reklam grubu için taslak açılamaz' : null,
            default => null,
        };
    }

    public function presentAction(AnalystDecision $decision): ?array
    {
        $spec = self::ACTIONS[$decision->action_type] ?? null;
        if ($spec === null) {
            return null;
        }
        $target = (string) ($decision->action_params['target'] ?? '');
        $fact = (array) ($decision->action_params['_target'] ?? []);
        $parts = explode(':', $target);
        $asset = ctype_digit($parts[1] ?? '') ? (int) $parts[1] : null;
        $ads = fn (array $params): ?string => $asset === null ? null : route('operator.google-ads.overview', ['assetId' => $asset] + $params);
        $url = match ($decision->action_type) {
            'add_negatives' => route('operator.brand', ['brand' => $decision->brand_id, 'tab' => 'google_ads', 'neg' => $decision->id]),
            'export_editor' => route('operator.analyst.google-ads.editor', ['brand' => $decision->brand_id, 'decision' => $decision->id]),
            'open_campaign' => ($parts[0] ?? '') === 'camp' ? $ads(['tab' => 'campaigns', 'campaign' => $parts[2] ?? null]) : $ads(['tab' => 'overview']),
            'fix_tracking' => ($parts[1] ?? '') === 'site'
                ? (isset($fact['site_id']) ? route('operator.website', ['assetId' => (int) $fact['site_id']]) : route('operator.brand', ['brand' => $decision->brand_id, 'tab' => 'ayarlar']))
                : $ads(['tab' => 'measurement']),
            'review_landing_page' => isset($fact['site_id']) && filled($fact['path'] ?? null)
                ? route('operator.website', ['assetId' => (int) $fact['site_id'], 'tab' => 'scorecard', 'url_q' => $fact['path']])
                : $ads(['tab' => 'landing_pages']),
            default => null,
        };

        return ['label' => $spec['label'], 'kind' => $spec['kind'], 'url' => $spec['kind'] === 'link' ? $url : null] + ($decision->action_type === 'export_editor' ? ['download' => true] : []);
    }

    public function perform(AnalystDecision $decision, User $user): string
    {
        if ($decision->action_type !== 'draft_ad_copy') {
            throw ValidationException::withMessages(['analyst' => 'Bu kart için çalıştırılacak bir işlem yok.']);
        }
        $item = $this->advisorItemFor($decision);
        if ($item === null) {
            throw ValidationException::withMessages(['analyst' => 'Reklam grubunun danışman önerisi kapanmış; yeniden analiz edin.']);
        }
        $message = app(AdvisorItemActions::class)->requestDraft($item);

        return $message === null ? 'Taslak zaten hazırlanıyor.' : $message.' Taslak reklam hesabının Danışman sekmesinde ve Editor dosyasında.';
    }

    public function baseline(AnalystDecision $decision): array
    {
        $brand = Brand::query()->find($decision->brand_id);
        $asset = self::accountOf((string) ($decision->action_params['target'] ?? ''));
        $account = $brand !== null && ctype_digit($asset) ? $this->facts->accountFor($brand, (int) $asset) : null;

        return $account === null ? [] : ['account' => (int) $asset, 'currency' => $account['currency'], 'cost_28d' => $account['cost'],
            'conversions_28d' => $account['conversions'], 'wasted_28d' => $account['wasted']];
    }

    /**
     * The negative list of an add_negatives card for the Admin review: the card's search terms of the target account
     * (exact match), plus the paste list of a cited advisor negative item; the account's current candidates otherwise.
     *
     * @return array{asset_id: int, lines: string, count: int}
     */
    public function negativeLines(AnalystDecision $decision): array
    {
        $asset = self::accountOf((string) ($decision->action_params['target'] ?? ''));
        if ($decision->action_type !== 'add_negatives' || ! ctype_digit($asset)) {
            throw ValidationException::withMessages(['analyst' => 'Bu kart negatif listesi değil.']);
        }
        $lines = [];
        foreach ((array) $decision->evidence as $row) {
            $id = (string) ($row['id'] ?? '');
            if (str_starts_with($id, 'st:'.$asset.':') && filled($row['text'] ?? null)) {
                $lines[] = '['.mb_strtolower(trim((string) $row['text'])).']';
            } elseif (str_starts_with($id, 'adv:')) {
                $item = AdvisorItem::query()->where('digital_asset_id', (int) $asset)->open()->find((int) substr($id, 4));
                foreach (preg_split('/\R/u', (string) $item?->copy_text) ?: [] as $line) {
                    if (trim($line) !== '') {
                        $lines[] = trim($line);
                    }
                }
            }
        }
        if ($lines === []) {
            $brand = Brand::query()->findOrFail($decision->brand_id);
            $account = $this->facts->accountFor($brand, (int) $asset);
            foreach ($account !== null ? $this->facts->negativeCandidates($account) : [] as $term) {
                $lines[] = '['.mb_strtolower($term['text']).']';
            }
        }
        $lines = array_values(array_unique($lines));

        return ['asset_id' => (int) $asset, 'lines' => implode("\n", $lines), 'count' => count($lines)];
    }

    /**
     * Google Ads Editor rows of an export_editor card (the card's own items): campaign-level exact negatives for its
     * search terms, exact keywords for converting terms, and the ready AI RSA draft of its ad groups (sector-rule
     * lines dropped).
     *
     * @return array{rows: list<array<string, string>>, manual: int}
     */
    public function editorRows(AnalystDecision $decision): array
    {
        $asset = self::accountOf((string) ($decision->action_params['target'] ?? ''));
        $compliance = BriefCompliance::forBrand(Brand::query()->find($decision->brand_id));
        $rows = [];
        $manual = 0;
        foreach ((array) $decision->evidence as $row) {
            $id = (string) ($row['id'] ?? '');
            if (str_starts_with($id, 'st:'.$asset.':')) {
                $campaigns = array_filter((array) ($row['campaigns'] ?? []), 'is_string');
                if ($campaigns === [] || blank($row['text'] ?? null)) {
                    $manual++;

                    continue;
                }
                foreach ($campaigns as $campaign) {
                    $rows[] = ['Campaign' => $campaign, 'Keyword' => mb_strtolower((string) $row['text']), 'Criterion Type' => 'Campaign Negative Exact'];
                }
            } elseif (str_starts_with($id, 'kwo:'.$asset.':')) {
                if (blank($row['campaign'] ?? null) || blank($row['ad_group'] ?? null)) {
                    $manual++;

                    continue;
                }
                $rows[] = ['Campaign' => (string) $row['campaign'], 'Ad group' => (string) $row['ad_group'], 'Keyword' => (string) $row['text'], 'Criterion Type' => 'Exact'];
            } elseif (str_starts_with($id, 'ad:'.$asset.':')) {
                $item = filled($row['advisor_item_id'] ?? null) ? AdvisorItem::query()->where('digital_asset_id', (int) $asset)->find((int) $row['advisor_item_id']) : null;
                $draft = $item !== null && $item->draft_status === 'ready' && is_array($item->draft) && ! isset($item->draft['error']) ? $item->draft : null;
                if ($draft === null || blank($row['campaign'] ?? null) || blank($row['name'] ?? null)) {
                    $manual++;

                    continue;
                }
                $rows[] = GoogleAdsEditorExport::rsaRow((string) $row['campaign'], (string) $row['name'], $draft, (string) ($row['final_url'] ?? ''), fn (string $line): bool => $compliance->isCompliant($line));
            }
        }

        return ['rows' => app(GoogleAdsEditorExport::class)->unique($rows), 'manual' => $manual];
    }

    public function advisorItemFor(AnalystDecision $decision): ?AdvisorItem
    {
        $target = (string) ($decision->action_params['target'] ?? '');
        $itemId = (int) ($decision->action_params['_target']['advisor_item_id'] ?? 0);
        $asset = self::accountOf($target);
        if ($itemId <= 0 || ! ctype_digit($asset)) {
            return null;
        }

        return AdvisorItem::query()->where('digital_asset_id', (int) $asset)->where('rule_id', 'weak-ad-strength')->open()->find($itemId);
    }

    /** The account (asset id) a pack id belongs to: "camp:12:345" → "12"; "trk:site:x" → "site". */
    public static function accountOf(string $ref): string
    {
        return explode(':', $ref)[1] ?? '';
    }

    /** @return array<string, mixed> */
    private function context(Brand $brand): array
    {
        $rules = [];
        try {
            $rules = app(SectorPackRegistry::class)->rulesForBrand($brand)->filter(fn ($rule): bool => $rule->appliesTo('google_ads_ad'))
                ->map(fn ($rule): string => mb_substr($rule->label.': '.$rule->message, 0, 160))->values()->take(10)->all();
        } catch (Throwable $exception) {
            report($exception);
        }
        $accounts = $this->facts->accounts($brand);

        return [
            'brand' => $brand->name,
            'sector' => implode(', ', array_filter(array_map(fn (string $c): string => (string) IndustryOptions::label($c), $brand->sectorCodes()))),
            'window_days' => GoogleAdsFacts::WINDOW_DAYS,
            'accounts' => count($accounts),
            'currencies' => array_values(array_unique(array_column($accounts, 'currency'))),
            'service_areas' => $brand->serviceAreas()->where('status', 'active')->orderBy('priority_rank')->limit(10)->get()->map->label()->values()->all(),
            'ad_rules' => $rules,
        ];
    }

    /**
     * @param  array<string, mixed>  $account
     * @return array<string, mixed>
     */
    private function accountFact(array $account): array
    {
        $targets = (array) ($account['input']['targets'] ?? []);

        return [
            'name' => $account['name'], 'currency' => $account['currency'], 'customer_id' => $account['customer_id'],
            'cost' => $account['cost'], 'cost_prev' => $account['cost_prev'], 'cost_delta_pct' => GoogleAdsFacts::delta($account['cost'], $account['cost_prev']),
            'clicks' => $account['clicks'], 'conversions' => $account['conversions'], 'conversions_prev' => $account['conversions_prev'],
            'conversions_delta_pct' => GoogleAdsFacts::delta($account['conversions'], $account['conversions_prev']),
            'cpa' => $account['conversions'] > 0 ? round($account['cost'] / $account['conversions'], 2) : null,
            'cpa_prev' => $account['conversions_prev'] > 0 ? round($account['cost_prev'] / $account['conversions_prev'], 2) : null,
            'roas' => $account['cost'] > 0 && $account['value'] > 0 ? round($account['value'] / $account['cost'], 2) : null,
            'wasted' => $account['wasted'], 'wasted_share_pct' => $account['cost'] > 0 ? (int) round($account['wasted'] / $account['cost'] * 100) : null,
            'impression_share' => $account['is_n'] > 0 ? (int) round($account['is_w'] / $account['is_n'] * 100) : null,
            'lost_is_budget' => $account['l_n'] > 0 ? (int) round($account['lb_w'] / $account['l_n'] * 100) : null,
            'lost_is_rank' => $account['l_n'] > 0 ? (int) round($account['lr_w'] / $account['l_n'] * 100) : null,
            'target_cpa' => $targets['target_cpa'] ?? null, 'target_roas' => $targets['target_roas'] ?? null,
            'campaigns' => count($account['campaigns']),
        ];
    }

    /**
     * Tracking issues of all accounts; website issues counted once.
     *
     * @param  list<array<string, mixed>>  $accounts
     * @return array<string, array<string, mixed>>
     */
    private function trackingIssues(array $accounts): array
    {
        $out = [];
        foreach ($accounts as $account) {
            foreach ($account['tracking'] as $code => $issue) {
                $out[($issue['scope'] ?? 'account') === 'site' ? $code : $account['asset_id'].':'.$code] = $issue;
            }
        }
        uasort($out, fn (array $a, array $b): int => array_search($a['severity'], ['critical', 'high', 'medium', 'low'], true) <=> array_search($b['severity'], ['critical', 'high', 'medium', 'low'], true));

        return $out;
    }

    /** @return array<string, mixed>|null the rule-code tracking card when the AI left it out */
    private function trackingDecision(AnalystPack $pack): ?array
    {
        $order = ['critical' => 0, 'high' => 1];
        $best = null;
        foreach ((array) ($pack->sections()['tracking'] ?? []) as $ref => $fact) {
            if (isset($order[$fact['severity']]) && ($best === null || $order[$fact['severity']] < $order[$pack->fact($best)['severity']])) {
                $best = (string) $ref;
            }
        }
        if ($best === null) {
            return null;
        }
        $fact = (array) $pack->fact($best);
        $account = self::accountOf($best);
        $acct = ctype_digit($account) ? $pack->fact('acct:'.$account) : null;
        $issue = mb_strtolower(mb_substr((string) $fact['issue'], 0, 60));
        $why = $acct !== null
            ? sprintf('Hesap %d günde %s harcadı ama %s; diğer kararlar buna bağlı.', GoogleAdsFacts::WINDOW_DAYS, GoogleAdsFacts::money((float) $acct['cost'], (string) $acct['currency']), $issue)
            : sprintf('Son %d günün reklam sonuçları güvenilir değil: %s.', GoogleAdsFacts::WINDOW_DAYS, $issue);

        return [
            'key' => 'tracking:'.$best, 'title_tr' => mb_substr('Dönüşüm takibini düzelt: '.$fact['issue'], 0, self::TITLE_MAX), 'why_tr' => mb_substr($why, 0, self::WHY_MAX),
            'priority' => 1, 'effort' => 'low', 'impact' => ['estimate' => 'doğru ölçüm', 'basis' => 'takip kontrolü'],
            'evidence_refs' => array_values(array_filter([$best, $acct !== null ? 'acct:'.$account : null])), 'action' => ['type' => 'fix_tracking', 'params' => ['target' => $best]],
        ];
    }

    private static function number(float $value): string
    {
        return rtrim(rtrim(number_format($value, 1, ',', '.'), '0'), ',');
    }
}
