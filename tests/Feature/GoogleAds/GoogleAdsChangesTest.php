<?php

namespace Tests\Feature\GoogleAds;

use App\Enums\CustomerStatus;
use App\Enums\DigitalAssetStatus;
use App\Models\Brand;
use App\Models\CoreAssetBinding;
use App\Models\CoreExternalResource;
use App\Models\CoreIntegration;
use App\Models\CoreIntegrationCredential;
use App\Models\Customer;
use App\Models\DigitalAsset;
use App\Models\ExternalWriteAction;
use App\Models\Suggestion;
use App\Models\User;
use App\Services\ExternalWrites\ExternalWriteService;
use App\Services\GoogleAds\GoogleAdsChanges;
use App\Services\GoogleAds\GoogleAdsSpecialistBindingResolver;
use App\Services\GoogleAds\GoogleAdsSuggestions;
use App\Services\Integrations\Google\GoogleApiClient;
use App\Services\Repair\RepairDesk;
use App\Support\Integrations\Google\GoogleResourceType;
use App\Support\Integrations\Google\GoogleScopes;
use App\Support\Roles;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Tests\TestCase;

/**
 * ADR-081 (Onarım Faz 5): the nightly read of every Google Ads account prepares setting changes (Search Partners,
 * Display network, location option, auto-tagging, budget +20%, pausing a no-conversion keyword); each goes to Google
 * only after the Admin approves it, after a fresh read and a validateOnly check, and undo writes the previous value back.
 */
final class GoogleAdsChangesTest extends TestCase
{
    use RefreshDatabase;

    private const string C = 'customers/1112223333';

    private User $admin;

    private DigitalAsset $asset;

    /** @var array<string, mixed> Google's live state */
    private array $state = [
        'auto_tagging' => false,
        'campaigns' => [
            '77' => ['type' => 'SEARCH', 'search' => true, 'content' => false, 'geo' => 'PRESENCE_OR_INTEREST', 'cost' => 500_000_000, 'conv' => 10.0, 'lost' => 0.35, 'budget' => '77'],
            '88' => ['type' => 'PERFORMANCE_MAX', 'search' => false, 'content' => false, 'geo' => 'PRESENCE', 'cost' => 500_000_000, 'conv' => 10.0, 'lost' => 0.0, 'budget' => '88'],
        ],
        'budgets' => ['77' => 100_000_000, '88' => 50_000_000],
        'keywords' => ['5~9' => 'ENABLED'],
    ];

    /** @var list<array{0: string, 1: array<string, mixed>}> */
    private array $mutations = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleAndPermissionSeeder::class);
        config(['moxdop.google.client_id' => 'cid', 'moxdop.google.client_secret' => 'csecret', 'moxdop.google.developer_token' => 'devtoken']);
        $this->admin = User::factory()->create(['is_active' => true]);
        $this->admin->assignRole(Roles::ADMIN);
        $brand = Brand::factory()->create(['customer_id' => Customer::factory()->create(['status' => CustomerStatus::Active])->id]);
        $this->asset = DigitalAsset::factory()->create(['brand_id' => $brand->id, 'type' => 'google_ads', 'module_id' => 'google_ads', 'status' => DigitalAssetStatus::Active]);
        $integration = CoreIntegration::factory()->google()->create(['status' => CoreIntegration::STATUS_ACTIVE, 'config' => ['granted_scopes' => [GoogleScopes::ADWORDS]]]);
        CoreIntegrationCredential::factory()->provider()->create(['integration_id' => $integration->id, 'encrypted_payload' => ['client_id' => 'cid', 'client_secret' => 'csecret', 'developer_token' => 'devtoken']]);
        CoreIntegrationCredential::factory()->authorization()->create(['integration_id' => $integration->id, 'encrypted_payload' => ['access_token' => 'a', 'refresh_token' => 'r', 'scope' => GoogleScopes::ADWORDS], 'expires_at' => now()->addHour()]);
        $resource = CoreExternalResource::factory()->create(['integration_id' => $integration->id, 'provider' => 'google', 'resource_type' => GoogleResourceType::GOOGLE_ADS_CUSTOMER,
            'external_id' => '1112223333', 'status' => CoreExternalResource::STATUS_AVAILABLE, 'metadata' => ['currency' => 'TRY']]);
        CoreAssetBinding::factory()->create(['digital_asset_id' => $this->asset->id, 'external_resource_id' => $resource->id, 'capability' => GoogleAdsSpecialistBindingResolver::CAPABILITY, 'status' => CoreAssetBinding::STATUS_ACTIVE]);

        Http::fake(function (Request $request) {
            $url = $request->url();
            if (str_contains($url, 'oauth2')) {
                return Http::response(['access_token' => 'fresh', 'expires_in' => 3600]);
            }
            if (str_contains($url, 'googleAds:search')) {
                return Http::response(['results' => $this->search((string) ($request->data()['query'] ?? ''))]);
            }
            if (str_contains($url, ':mutate')) {
                $this->mutations[] = [$url, $request->data()];
                if (! ($request->data()['validateOnly'] ?? false)) {
                    $this->apply($url, $request->data());
                }

                return Http::response(['results' => [['resourceName' => 'x']]]);
            }

            return Http::response([], 404);
        });
    }

    /** @return list<array<string, mixed>> */
    private function search(string $query): array
    {
        if (str_contains($query, 'FROM customer')) {
            return [['customer' => ['autoTaggingEnabled' => $this->state['auto_tagging'], 'currencyCode' => 'TRY']]];
        }
        $campaign = fn (string $id, array $c): array => ['campaign' => ['resourceName' => self::C.'/campaigns/'.$id, 'name' => 'Kampanya '.$id, 'advertisingChannelType' => $c['type'],
            'networkSettings' => ['targetSearchNetwork' => $c['search'], 'targetContentNetwork' => $c['content']], 'geoTargetTypeSetting' => ['positiveGeoTargetType' => $c['geo']]],
            'campaignBudget' => ['resourceName' => self::C.'/campaignBudgets/'.$c['budget'], 'amountMicros' => (string) $this->state['budgets'][$c['budget']], 'explicitlyShared' => false],
            'metrics' => ['costMicros' => (string) $c['cost'], 'conversions' => $c['conv'], 'searchBudgetLostImpressionShare' => $c['lost']]];
        if (preg_match("~FROM campaign WHERE campaign.resource_name = '.+/campaigns/(\d+)'~", $query, $m) === 1) {
            return [$campaign($m[1], $this->state['campaigns'][$m[1]])];
        }
        if (str_contains($query, 'FROM campaign WHERE')) {
            return array_map($campaign, array_keys($this->state['campaigns']), $this->state['campaigns']);
        }
        if (preg_match("~FROM campaign_budget WHERE .+/campaignBudgets/(\d+)'~", $query, $m) === 1) {
            return [['campaignBudget' => ['amountMicros' => (string) $this->state['budgets'][$m[1]]]]];
        }
        if (preg_match("#FROM ad_group_criterion WHERE .+/adGroupCriteria/([\d~]+)'#", $query, $m) === 1) {
            return [['adGroupCriterion' => ['status' => $this->state['keywords'][$m[1]]]]];
        }
        if (str_contains($query, 'FROM keyword_view')) {
            return $this->state['keywords']['5~9'] === 'ENABLED' ? [['adGroupCriterion' => ['resourceName' => self::C.'/adGroupCriteria/5~9', 'keyword' => ['text' => 'ücretsiz implant', 'matchType' => 'BROAD']],
                'campaign' => ['name' => 'Kampanya 77'], 'metrics' => ['costMicros' => '150000000', 'clicks' => '40']]] : [];
        }

        return [];
    }

    /** @param  array<string, mixed>  $body */
    private function apply(string $url, array $body): void
    {
        $operation = $body['operation'] ?? $body['operations'][0];
        $update = $operation['update'];
        $id = (string) substr((string) strrchr($update['resourceName'], '/'), 1);
        match ($operation['updateMask']) {
            'auto_tagging_enabled' => $this->state['auto_tagging'] = $update['autoTaggingEnabled'],
            'network_settings.target_search_network' => $this->state['campaigns'][$id]['search'] = $update['networkSettings']['targetSearchNetwork'],
            'network_settings.target_content_network' => $this->state['campaigns'][$id]['content'] = $update['networkSettings']['targetContentNetwork'],
            'geo_target_type_setting.positive_geo_target_type' => $this->state['campaigns'][$id]['geo'] = $update['geoTargetTypeSetting']['positiveGeoTargetType'],
            'amount_micros' => $this->state['budgets'][$id] = (int) $update['amountMicros'],
            'status' => $this->state['keywords'][$id] = $update['status'],
        };
    }

    /** @return array<string, array<string, mixed>> field => repair desk row */
    private function prepared(): array
    {
        $this->artisan('moxdop:repair:audit')->assertSuccessful();

        return app(RepairDesk::class)->rows(kind: RepairDesk::ADS_CHANGE)
            ->keyBy(fn (array $r): string => (string) Suggestion::query()->find($r['id'])->action['field'])->all();
    }

    public function test_nightly_audit_prepares_the_setting_fixes_with_risk(): void
    {
        $rows = $this->prepared();

        $this->assertEqualsCanonicalizing(['auto_tagging', 'search_partners', 'location_option', 'budget', 'keyword_status'], array_keys($rows));
        $this->assertSame('low', $rows['search_partners']['risk']);
        $this->assertSame('medium', $rows['budget']['risk']);
        $this->assertStringContainsString('100 TRY → 120 TRY', $rows['budget']['after'][0]);
        $this->assertStringContainsString('ücretsiz implant', $rows['keyword_status']['target']);
        $this->assertSame([], $this->mutations, 'the audit only reads');
    }

    public function test_bulk_approval_writes_each_change_after_a_validate_only_check_and_undo_restores_it(): void
    {
        $rows = $this->prepared();

        $result = app(RepairDesk::class)->approve(array_column($rows, 'id'), $this->admin);

        $this->assertSame(5, $result['applied'], implode(' ', $result['failed']));
        $this->assertTrue($this->state['auto_tagging']);
        $this->assertFalse($this->state['campaigns']['77']['search']);
        $this->assertSame('PRESENCE', $this->state['campaigns']['77']['geo']);
        $this->assertSame(120_000_000, $this->state['budgets']['77']);
        $this->assertSame('PAUSED', $this->state['keywords']['5~9']);
        $this->assertCount(10, $this->mutations, 'every change: validateOnly first, then the write');
        $this->assertTrue($this->mutations[0][1]['validateOnly']);
        $this->assertSame(5, Suggestion::query()->whereIn('id', array_column($rows, 'id'))->where('status', Suggestion::APPLIED)->count());

        $budget = ExternalWriteAction::query()->where('action', ExternalWriteAction::ACTION_ADS_CHANGE)->get()->firstWhere('request_payload.field', 'budget');
        app(ExternalWriteService::class)->requestUndo($this->admin, $budget);

        $this->assertSame('undone', $budget->refresh()->status, (string) $budget->error);
        $this->assertSame(100_000_000, $this->state['budgets']['77']);
    }

    public function test_a_value_changed_on_google_since_preparation_stops_the_write_and_returns_the_row(): void
    {
        $rows = $this->prepared();
        $this->state['campaigns']['77']['geo'] = 'PRESENCE';

        $result = app(RepairDesk::class)->approve([$rows['location_option']['id']], $this->admin);

        $this->assertSame(1, $result['applied'], 'the request is queued; the write itself fails');
        $suggestion = Suggestion::query()->find($rows['location_option']['id']);
        $this->assertSame(Suggestion::OPEN, $suggestion->status);
        $this->assertStringContainsString('Hesapta değer değişmiş', (string) $suggestion->action['last_write_error']);
        $this->assertSame([], $this->mutations);
    }

    public function test_budget_steps_are_capped_and_only_allowed_fields_can_be_mutated(): void
    {
        try {
            app(ExternalWriteService::class)->requestAdsChange($this->admin, $this->asset, ['field' => 'budget', 'resource' => self::C.'/campaignBudgets/77',
                'before' => 100_000_000, 'after' => 200_000_000, 'label' => 'x']);
            $this->fail('a budget doubling must be refused');
        } catch (ValidationException $exception) {
            $this->assertStringContainsString('%30', (string) collect($exception->errors())->flatten()->first());
        }

        $integration = CoreIntegration::query()->firstOrFail();
        $this->expectException(RuntimeException::class);
        app(GoogleApiClient::class)->mutateAdsSettings($integration, '1112223333', 'campaigns', ['operations' => [
            ['update' => ['resourceName' => self::C.'/campaigns/77', 'biddingStrategyType' => 'MANUAL_CPC'], 'updateMask' => 'bidding_strategy_type'],
        ]]);
    }

    public function test_approving_on_the_google_ads_screen_writes_the_change(): void
    {
        $rows = $this->prepared();
        $suggestion = Suggestion::query()->find($rows['search_partners']['id']);

        app(GoogleAdsSuggestions::class)->approve($suggestion, $this->admin);

        $this->assertFalse($this->state['campaigns']['77']['search']);
        $this->assertSame(Suggestion::APPLIED, $suggestion->refresh()->status);
        $this->assertNotContains($suggestion->id, app(GoogleAdsSuggestions::class)->editorDrafts($this->asset)->pluck('id')->all());
        $this->assertSame(GoogleAdsChanges::TYPE, $suggestion->action_type);
    }
}
