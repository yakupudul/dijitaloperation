<?php

namespace App\Services\GoogleAds;

use App\Models\DigitalAsset;
use App\Models\ExternalWriteAction;
use App\Models\Page;
use App\Models\Suggestion;
use App\Models\User;
use App\Services\Advisor\GoogleAds\GoogleAdsAdvisorInputCollector;
use App\Services\ExternalWrites\ExternalWriteService;
use App\Services\ExternalWrites\GoogleAdsChangeWriter;
use App\Services\Integrations\Google\GoogleApiClient;
use App\Services\SeoTasks\SeoText;
use Illuminate\Http\Client\Response;
use RuntimeException;
use Throwable;

/**
 * Google Ads onarımı (Onarım Faz 5, ADR-081): a nightly read-only check of every active Google Ads account that turns
 * the cheap, high-value setting fixes into prepared changes (`ads_change` suggestions) for the Onarım masası:
 *  - Search campaigns on Search Partners or the Display network → off, only when that network spent without a conversion;
 *  - location option "presence or interest" → "presence" (medium; never for a brand with a foreign-language audience);
 *  - auto-tagging off → on (low risk);
 *  - a keyword with ≥ 30 clicks, spend of at least twice its campaign's cost per conversion and no conversion in the
 *    30 days before the conversion lag → paused (medium; never a brand keyword);
 *  - a campaign with ≥ 10 conversions losing ≥ 20% of impressions to budget while converting at or below the account's
 *    cost per conversion → budget +20% (medium; shared budgets left alone; not again within 30 days of a budget write).
 * Nothing is written here: each change goes to Google only after the Admin approves it (GoogleAdsChangeWriter).
 */
final class GoogleAdsChanges
{
    public const string TYPE = 'ads_change';

    /** Keyword spend / account CPA ratio that makes a no-conversion keyword a pause candidate. */
    public const float KEYWORD_CPA_MULTIPLE = 2.0;

    public const float BUDGET_LOST_SHARE = 0.20;

    public const float BUDGET_STEP = 0.20;

    /** Below this many conversions in 30 days the account's conversion data is too thin for keyword / budget changes. */
    public const int MIN_ACCOUNT_CONVERSIONS = 5;

    /** A keyword is judged on at least this many clicks. */
    public const int MIN_KEYWORD_CLICKS = 30;

    public function __construct(
        private readonly GoogleApiClient $google,
        private readonly GoogleAdsChangeWriter $writer,
    ) {}

    /** @return array{accounts: int, prepared: int, failed: int} */
    public function auditAll(): array
    {
        $done = ['accounts' => 0, 'prepared' => 0, 'failed' => 0];
        $assets = DigitalAsset::query()->operational()->where('type', 'google_ads')->whereNotNull('brand_id')->orderBy('id')->get();
        foreach ($assets as $asset) {
            try {
                $items = $this->items($asset);
            } catch (Throwable $error) {
                $done['failed']++;
                if (! $error instanceof RuntimeException) {
                    report($error);
                }

                continue;
            }
            $done['accounts']++;
            $done['prepared'] += app(GoogleAdsSuggestions::class)->replaceGroup($asset, 'changes', $items);
        }

        return $done;
    }

    /**
     * @return list<array{key: string, title: string, reason: string, priority: int, evidence: array<mixed>, action_type: string, action: array<string, mixed>}>
     */
    public function items(DigitalAsset $asset): array
    {
        [$integration, $customerId, $login] = $this->writer->account((int) $asset->id);
        $search = fn (string $query): array => $this->rows($this->google->searchAds($integration, $customerId, $query, $login));
        $customer = $search('SELECT customer.auto_tagging_enabled, customer.currency_code FROM customer')[0] ?? [];
        $currency = (string) data_get($customer, 'customer.currencyCode', '');
        $campaigns = $search("SELECT campaign.resource_name, campaign.name, campaign.advertising_channel_type, campaign.network_settings.target_search_network, campaign.network_settings.target_content_network, campaign.geo_target_type_setting.positive_geo_target_type, campaign_budget.resource_name, campaign_budget.amount_micros, campaign_budget.explicitly_shared, metrics.cost_micros, metrics.conversions, metrics.search_budget_lost_impression_share FROM campaign WHERE campaign.status = 'ENABLED' AND segments.date DURING LAST_30_DAYS");
        if ($campaigns === []) {
            return [];
        }
        $cost = array_sum(array_map(fn (array $c): int => (int) data_get($c, 'metrics.costMicros', 0), $campaigns));
        $conversions = array_sum(array_map(fn (array $c): float => (float) data_get($c, 'metrics.conversions', 0), $campaigns));
        $cpa = $conversions > 0 ? $cost / $conversions : null;
        $money = fn (int|float $micros): string => number_format($micros / 1_000_000, $micros < 100_000_000 && fmod($micros / 1_000_000, 1.0) !== 0.0 ? 2 : 0, ',', '.').($currency !== '' ? ' '.$currency : '');
        // Per network: Partners / Display are proposed off only when they spent without a conversion.
        $networks = [];
        foreach ($search("SELECT campaign.resource_name, segments.ad_network_type, metrics.cost_micros, metrics.conversions FROM campaign WHERE campaign.status = 'ENABLED' AND campaign.advertising_channel_type = 'SEARCH' AND segments.date DURING LAST_30_DAYS") as $row) {
            $key = (string) data_get($row, 'campaign.resourceName').'|'.data_get($row, 'segments.adNetworkType');
            $networks[$key] = [($networks[$key][0] ?? 0) + (int) data_get($row, 'metrics.costMicros', 0), ($networks[$key][1] ?? 0.0) + (float) data_get($row, 'metrics.conversions', 0)];
        }
        $wasted = fn (string $resource, string $network): ?int => isset($networks[$resource.'|'.$network]) && $networks[$resource.'|'.$network][0] > 0 && $networks[$resource.'|'.$network][1] <= 0
            ? $networks[$resource.'|'.$network][0] : null;
        $foreignAudience = $this->foreignAudience($asset);
        $recentBudgets = $this->recentBudgetWrites($asset);
        $items = [];
        $item = function (string $key, string $title, string $reason, int $priority, string $field, string $resource, mixed $before, mixed $after, string $label, string $target, string $risk) use (&$items): void {
            $items[] = ['key' => 'changes:'.$key, 'title' => $title, 'reason' => $reason, 'priority' => $priority,
                'evidence' => [['öğe' => $target, 'şimdi' => GoogleAdsChangeWriter::text($before), 'önerilen' => GoogleAdsChangeWriter::text($after)]],
                'action_type' => self::TYPE, 'action' => ['field' => $field, 'resource' => $resource, 'before' => $before, 'after' => $after,
                    'label' => $label, 'target' => $target, 'risk' => $risk]];
        };

        if (data_get($customer, 'customer.autoTaggingEnabled') === false) {
            $item('auto_tagging', 'Otomatik etiketleme kapalı', 'Kapalıyken Google Analytics ve dönüşüm eşleştirmesi tıklamayı kampanyaya bağlayamaz.', 1,
                'auto_tagging', 'customers/'.$customerId, false, true, 'Otomatik etiketlemeyi aç', 'Hesap', 'low');
        }
        foreach ($campaigns as $campaign) {
            $resource = (string) data_get($campaign, 'campaign.resourceName');
            $name = (string) data_get($campaign, 'campaign.name');
            $type = (string) data_get($campaign, 'campaign.advertisingChannelType');
            $spend = (int) data_get($campaign, 'metrics.costMicros', 0);
            if ($type === 'SEARCH' && data_get($campaign, 'campaign.networkSettings.targetSearchNetwork') === true && ($waste = $wasted($resource, 'SEARCH_PARTNERS')) !== null) {
                $item('search_partners:'.$resource, 'Arama ağı iş ortakları açık', 'Arama kampanyası Google dışındaki ortak sitelerde de gösteriliyor; son 30 günde orada '.$money($waste).' harcadı, dönüşüm yok.',
                    2, 'search_partners', $resource, true, false, 'Arama ağı iş ortaklarını kapat · '.$name, $name, 'low');
            }
            if ($type === 'SEARCH' && data_get($campaign, 'campaign.networkSettings.targetContentNetwork') === true && ($waste = $wasted($resource, 'CONTENT')) !== null) {
                $item('display_network:'.$resource, 'Arama kampanyası Görüntülü Reklam Ağı\'nda', 'Arama kampanyası banner alanlarına da taşıyor; son 30 günde orada '.$money($waste).' harcadı, dönüşüm yok.',
                    1, 'display_network', $resource, true, false, 'Görüntülü Reklam Ağı\'nı kapat · '.$name, $name, 'low');
            }
            // A brand with patients from abroad (foreign-language site / languages) targets "interest" on purpose.
            if (! $foreignAudience && in_array($type, ['SEARCH', 'PERFORMANCE_MAX'], true) && data_get($campaign, 'campaign.geoTargetTypeSetting.positiveGeoTargetType') === 'PRESENCE_OR_INTEREST') {
                $item('location_option:'.$resource, 'Konum seçeneği "ilgilenenler" de', 'Reklam hedef bölgede olmayan ama bölgeyle "ilgilenen" kişilere de gösteriliyor; markanın yabancı dilde sayfası yok, yerel işletmede yalnız bölgedeki kişiler hedeflenmeli.',
                    1, 'location_option', $resource, 'PRESENCE_OR_INTEREST', 'PRESENCE', 'Konum seçeneği: yalnız bölgede bulunanlar · '.$name, $name, 'medium');
            }
            $lost = (float) data_get($campaign, 'metrics.searchBudgetLostImpressionShare', 0);
            $budget = (int) data_get($campaign, 'campaignBudget.amountMicros', 0);
            $campaignConversions = (float) data_get($campaign, 'metrics.conversions', 0);
            // Not again within 30 days of a budget write (its window would still show the old loss: +20% would compound).
            if ($cpa !== null && $conversions >= self::MIN_ACCOUNT_CONVERSIONS && $lost >= self::BUDGET_LOST_SHARE && $budget > 0 && $campaignConversions >= GoogleAdsChecks::MIN_CONVERSIONS
                && data_get($campaign, 'campaignBudget.explicitlyShared') !== true && $spend / $campaignConversions <= $cpa
                && ! isset($recentBudgets[(string) data_get($campaign, 'campaignBudget.resourceName')])) {
                $after = (int) (round($budget * (1 + self::BUDGET_STEP) / 10_000) * 10_000);
                $item('budget:'.data_get($campaign, 'campaignBudget.resourceName'), 'Bütçe yüzünden gösterim kaybı', sprintf('Gösterimlerin %%%d\'i bütçe yetmediği için kaçıyor; kampanya dönüşüm başına %s ile hesap ortalamasının (%s) altında. Günlük bütçe %%20 artırılır.',
                    (int) round($lost * 100), $money($spend / $campaignConversions), $money($cpa)), 2, 'budget', (string) data_get($campaign, 'campaignBudget.resourceName'), $budget, $after,
                    'Günlük bütçe '.$money($budget).' → '.$money($after).' · '.$name, $name, 'medium');
            }
        }
        if ($cpa !== null && $conversions >= self::MIN_ACCOUNT_CONVERSIONS) {
            // Each campaign is judged by its own cost per conversion (an account average is pulled down by brand / cheap
            // services); the last conversion-lag days are left out; at least MIN_KEYWORD_CLICKS clicks; brand keywords stay.
            $campaignCpa = [];
            foreach ($campaigns as $c) {
                $conv = (float) data_get($c, 'metrics.conversions', 0);
                $campaignCpa[(string) data_get($c, 'campaign.name')] = $conv > 0 ? max($cpa, (int) data_get($c, 'metrics.costMicros', 0) / $conv) : $cpa;
            }
            $to = now()->subDays(GoogleAdsAdvisorInputCollector::DEFAULT_CONVERSION_LAG_DAYS);
            $keywords = $search(sprintf("SELECT ad_group_criterion.resource_name, ad_group_criterion.keyword.text, ad_group_criterion.keyword.match_type, campaign.name, metrics.cost_micros, metrics.clicks FROM keyword_view WHERE segments.date BETWEEN '%s' AND '%s' AND ad_group_criterion.status = 'ENABLED' AND ad_group.status = 'ENABLED' AND campaign.status = 'ENABLED' AND metrics.conversions = 0 AND metrics.clicks >= %d AND metrics.cost_micros > %d ORDER BY metrics.cost_micros DESC LIMIT 50",
                $to->copy()->subDays(29)->toDateString(), $to->toDateString(), self::MIN_KEYWORD_CLICKS, (int) round($cpa * self::KEYWORD_CPA_MULTIPLE)));
            $brandWords = array_filter(explode(' ', SeoText::fold((string) $asset->brand?->name)), fn (string $w): bool => mb_strlen($w) >= 4);
            foreach ($keywords as $keyword) {
                $text = (string) data_get($keyword, 'adGroupCriterion.keyword.text');
                $limit = ($campaignCpa[(string) data_get($keyword, 'campaign.name')] ?? $cpa) * self::KEYWORD_CPA_MULTIPLE;
                if ((int) data_get($keyword, 'metrics.costMicros', 0) <= $limit
                    || collect($brandWords)->contains(fn (string $w): bool => str_contains(' '.SeoText::fold($text).' ', ' '.$w.' '))) {
                    continue;
                }
                $resource = (string) data_get($keyword, 'adGroupCriterion.resourceName');
                $target = sprintf('"%s" (%s) · %s', $text, strtolower((string) data_get($keyword, 'adGroupCriterion.keyword.matchType')), data_get($keyword, 'campaign.name'));
                $item('keyword:'.$resource, 'Dönüşümsüz pahalı anahtar kelime', sprintf('30 günde (dönüşümü henüz gelmemiş son %d gün hariç) %s harcadı, %d tıklama, dönüşüm yok (kampanyanın dönüşüm başına maliyeti %s). Durdurulur, silinmez.',
                    GoogleAdsAdvisorInputCollector::DEFAULT_CONVERSION_LAG_DAYS, $money((int) data_get($keyword, 'metrics.costMicros', 0)), (int) data_get($keyword, 'metrics.clicks', 0), $money($limit / self::KEYWORD_CPA_MULTIPLE)), 2,
                    'keyword_status', $resource, 'ENABLED', 'PAUSED', 'Anahtar kelimeyi durdur · '.$text, $target, 'medium');
            }
        }

        return $items;
    }

    /** The brand serves people from abroad: a foreign-language field on the brand, or ≥ 10 pages in another language. */
    private function foreignAudience(DigitalAsset $asset): bool
    {
        $brand = $asset->brand;
        if ($brand === null) {
            return false;
        }
        if (collect((array) ($brand->languages ?? []))->contains(fn ($l): bool => is_string($l) && strtolower(substr($l, 0, 2)) !== 'tr')) {
            return true;
        }
        $sites = DigitalAsset::query()->where('brand_id', $brand->id)->where('type', 'website')->pluck('id');

        return Page::query()->whereIn('website_asset_id', $sites->all() ?: [0])->whereNotNull('language')
            ->where('language', 'not like', 'tr%')->count() >= 10;
    }

    /** @return array<string, true> budget resources written (succeeded) in the last 30 days */
    private function recentBudgetWrites(DigitalAsset $asset): array
    {
        return ExternalWriteAction::query()->where('digital_asset_id', $asset->id)->where('action', ExternalWriteAction::ACTION_ADS_CHANGE)
            ->whereIn('status', ['succeeded', 'partial'])->where('created_at', '>=', now()->subDays(30))->get(['request_payload'])
            ->filter(fn (ExternalWriteAction $w): bool => data_get($w->request_payload, 'field') === 'budget')
            ->mapWithKeys(fn (ExternalWriteAction $w): array => [(string) data_get($w->request_payload, 'resource') => true])->all();
    }

    /** Sends one prepared change (Admin). */
    public function send(User $user, Suggestion $suggestion): ExternalWriteAction
    {
        $asset = DigitalAsset::query()->findOrFail((int) $suggestion->target_id);
        $action = (array) $suggestion->action;
        $write = app(ExternalWriteService::class)->requestAdsChange($user, $asset, $action, $suggestion);
        if ($suggestion->refresh()->status !== Suggestion::APPLIED && $write->refresh()->status !== 'failed') {
            $suggestion->forceFill(['status' => Suggestion::APPROVED, 'resolved_by' => $user->id, 'resolved_at' => now(),
                'action' => array_merge((array) $suggestion->action, ['sending_write_id' => $write->id])])->save();
        }

        return $write;
    }

    /** A finished change: applied when Google took it, otherwise back on the desk with the reason. */
    public function writeFinished(ExternalWriteAction $action): void
    {
        $suggestion = $action->suggestion_id !== null ? Suggestion::query()->find($action->suggestion_id) : null;
        if ($suggestion === null) {
            return;
        }
        $data = array_diff_key((array) $suggestion->action, ['sending_write_id' => true, 'last_write_error' => true]);
        if (in_array($action->status, ['succeeded', 'partial'], true)) {
            $suggestion->forceFill(['action' => $data])->save();
            app(GoogleAdsSuggestions::class)->markApplied($suggestion, $action->requested_by !== null ? User::query()->find($action->requested_by) : null, ['write_action_id' => $action->id]);

            return;
        }
        $suggestion->forceFill(['status' => Suggestion::OPEN, 'resolved_at' => null, 'resolved_by' => null,
            'action' => $data + ['last_write_error' => mb_substr((string) $action->error, 0, 300)]])->save();
    }

    /** @return list<array<string, mixed>> */
    private function rows(Response $response): array
    {
        if (! $response->successful()) {
            throw new RuntimeException('Google Ads okunamadı: '.mb_substr((string) (data_get($response->json(), 'error.details.0.errors.0.message') ?? data_get($response->json(), 'error.message') ?? 'HTTP '.$response->status()), 0, 200));
        }

        return array_values(array_filter((array) ($response->json('results') ?? []), 'is_array'));
    }
}
