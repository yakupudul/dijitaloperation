<?php

namespace App\Services\GoogleAds;

use App\Models\DigitalAsset;
use App\Models\ExternalWriteAction;
use App\Models\Suggestion;
use App\Models\User;
use App\Services\ExternalWrites\ExternalWriteService;
use App\Services\ExternalWrites\GoogleAdsChangeWriter;
use App\Services\Integrations\Google\GoogleApiClient;
use Illuminate\Http\Client\Response;
use RuntimeException;
use Throwable;

/**
 * Google Ads onarımı (Onarım Faz 5, ADR-081): a nightly read-only check of every active Google Ads account that turns
 * the cheap, high-value setting fixes into prepared changes (`ads_change` suggestions) for the Onarım masası:
 *  - Search campaigns on Search Partners or the Display network → off (low risk);
 *  - location option "presence or interest" → "presence" (people in the area; low risk);
 *  - auto-tagging off → on (low risk);
 *  - a keyword with spend of at least twice the account's cost per conversion and no conversion in 30 days → paused
 *    (medium; only when the account records conversions);
 *  - a campaign losing ≥ 20% of impressions to budget while converting at or below the account's cost per conversion →
 *    budget +20% (medium; shared budgets left alone).
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
        $money = fn (int|float $micros): string => number_format($micros / 1_000_000, 0, ',', '.').($currency !== '' ? ' '.$currency : '');
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
            if ($type === 'SEARCH' && data_get($campaign, 'campaign.networkSettings.targetSearchNetwork') === true) {
                $item('search_partners:'.$resource, 'Arama ağı iş ortakları açık', 'Arama kampanyası Google dışındaki ortak sitelerde de gösteriliyor; yerel hizmette genelde pahalı ve düşük kaliteli tıklama getirir. Son 30 gün harcama '.$money($spend).'.',
                    2, 'search_partners', $resource, true, false, 'Arama ağı iş ortaklarını kapat · '.$name, $name, 'low');
            }
            if ($type === 'SEARCH' && data_get($campaign, 'campaign.networkSettings.targetContentNetwork') === true) {
                $item('display_network:'.$resource, 'Arama kampanyası Görüntülü Reklam Ağı\'nda', 'Arama kampanyası banner alanlarına da taşıyor; bütçe arama niyeti olmayan gösterimlere gider.',
                    1, 'display_network', $resource, true, false, 'Görüntülü Reklam Ağı\'nı kapat · '.$name, $name, 'low');
            }
            if (in_array($type, ['SEARCH', 'PERFORMANCE_MAX'], true) && data_get($campaign, 'campaign.geoTargetTypeSetting.positiveGeoTargetType') === 'PRESENCE_OR_INTEREST') {
                $item('location_option:'.$resource, 'Konum seçeneği "ilgilenenler" de', 'Reklam hedef bölgede olmayan ama bölgeyle "ilgilenen" kişilere de gösteriliyor; yerel işletmede yalnız bölgedeki kişiler hedeflenmeli.',
                    1, 'location_option', $resource, 'PRESENCE_OR_INTEREST', 'PRESENCE', 'Konum seçeneği: yalnız bölgede bulunanlar · '.$name, $name, 'low');
            }
            $lost = (float) data_get($campaign, 'metrics.searchBudgetLostImpressionShare', 0);
            $budget = (int) data_get($campaign, 'campaignBudget.amountMicros', 0);
            $campaignConversions = (float) data_get($campaign, 'metrics.conversions', 0);
            if ($cpa !== null && $conversions >= self::MIN_ACCOUNT_CONVERSIONS && $lost >= self::BUDGET_LOST_SHARE && $budget > 0 && $campaignConversions >= 3
                && data_get($campaign, 'campaignBudget.explicitlyShared') !== true && $spend / $campaignConversions <= $cpa) {
                $after = (int) (round($budget * (1 + self::BUDGET_STEP) / 10_000) * 10_000);
                $item('budget:'.data_get($campaign, 'campaignBudget.resourceName'), 'Bütçe yüzünden gösterim kaybı', sprintf('Gösterimlerin %%%d\'i bütçe yetmediği için kaçıyor; kampanya dönüşüm başına %s ile hesap ortalamasının (%s) altında. Günlük bütçe %%20 artırılır.',
                    (int) round($lost * 100), $money($spend / $campaignConversions), $money($cpa)), 2, 'budget', (string) data_get($campaign, 'campaignBudget.resourceName'), $budget, $after,
                    'Günlük bütçe '.$money($budget).' → '.$money($after).' · '.$name, $name, 'medium');
            }
        }
        if ($cpa !== null && $conversions >= self::MIN_ACCOUNT_CONVERSIONS) {
            $threshold = (int) round($cpa * self::KEYWORD_CPA_MULTIPLE);
            $keywords = $search(sprintf("SELECT ad_group_criterion.resource_name, ad_group_criterion.keyword.text, ad_group_criterion.keyword.match_type, campaign.name, metrics.cost_micros, metrics.clicks FROM keyword_view WHERE segments.date DURING LAST_30_DAYS AND ad_group_criterion.status = 'ENABLED' AND ad_group.status = 'ENABLED' AND campaign.status = 'ENABLED' AND metrics.conversions = 0 AND metrics.cost_micros > %d ORDER BY metrics.cost_micros DESC LIMIT 20", $threshold));
            foreach ($keywords as $keyword) {
                $text = (string) data_get($keyword, 'adGroupCriterion.keyword.text');
                $resource = (string) data_get($keyword, 'adGroupCriterion.resourceName');
                $target = sprintf('"%s" (%s) · %s', $text, strtolower((string) data_get($keyword, 'adGroupCriterion.keyword.matchType')), data_get($keyword, 'campaign.name'));
                $item('keyword:'.$resource, 'Dönüşümsüz pahalı anahtar kelime', sprintf('Son 30 günde %s harcadı, %d tıklama, dönüşüm yok (hesabın dönüşüm başına maliyeti %s). Durdurulur, silinmez.',
                    $money((int) data_get($keyword, 'metrics.costMicros', 0)), (int) data_get($keyword, 'metrics.clicks', 0), $money($cpa)), 2,
                    'keyword_status', $resource, 'ENABLED', 'PAUSED', 'Anahtar kelimeyi durdur · '.$text, $target, 'medium');
            }
        }

        return $items;
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
