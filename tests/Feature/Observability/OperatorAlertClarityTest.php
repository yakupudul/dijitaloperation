<?php

namespace Tests\Feature\Observability;

use App\Enums\Collection\CollectionErrorCategory;
use App\Enums\Collection\CollectionRunStatus;
use App\Enums\DomainEventActorKind;
use App\Enums\DomainEventSubjectKind;
use App\Enums\DomainEventType;
use App\Enums\Observability\OperationalAlertRuleType;
use App\Enums\Observability\OperationalAlertSeverity;
use App\Enums\Observability\OperationalAlertState;
use App\Enums\Observability\OperationalSignalFamily;
use App\Livewire\Demo\NotificationBell;
use App\Livewire\Operator\Settings\SystemHealthPage;
use App\Models\Brand;
use App\Models\Collection\CollectionDatasetRun;
use App\Models\Collection\CollectionResourceRun;
use App\Models\Collection\CollectionRun;
use App\Models\CoreAssetBinding;
use App\Models\CoreExternalResource;
use App\Models\CoreIntegration;
use App\Models\DigitalAsset;
use App\Models\Observability\OperationalAlert;
use App\Models\ResourceAutomation;
use App\Models\User;
use App\Models\UserNotification;
use App\Services\CommandCenter\CommandCenter;
use App\Services\DomainEvents\DomainEventEmitter;
use App\Services\Integrations\ResourceAutomationService;
use App\Services\Notifications\NotificationReadService;
use App\Services\Observability\AlertSubjects;
use App\Services\Observability\OperationalAlertEvaluator;
use App\Services\Observability\OperationalAlertExplainer;
use App\Services\Observability\OperationalAlertLifecycleService;
use App\Support\Operator\OperatorMessage;
use App\Support\Roles;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Every system alert answers Ne oldu / Neden önemli / Ne yapmalısın / Nereden in plain Turkish, names the accounts,
 * links to the exact place, and one condition keeps one bell row.
 */
final class OperatorAlertClarityTest extends TestCase
{
    use RefreshDatabase;

    /** Words that must never reach the operator in an alert. */
    private const array JARGON = ['CollectionRun', 'dataset', 'veri seti', 'failed', 'stale', 'müdahale beklenir', 'Uyarılar sayfasından inceleyin', 'Prompt27'];

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleAndPermissionSeeder::class);
        app()->setLocale('tr');
        $this->admin = User::factory()->create();
        $this->admin->assignRole(Roles::ADMIN);
    }

    public function test_stopped_account_alert_names_the_account_says_why_and_offers_run_now_in_the_bell(): void
    {
        [$asset, $resource, $automation] = $this->searchConsoleAccount('Moximu', 'moximu.com', 'ops@moximu.com');
        $this->failedDataset($resource, $asset, CollectionErrorCategory::Authorization, 'gsc_property_daily');

        $service = app(ResourceAutomationService::class);
        foreach (range(1, 3) as $attempt) {
            $service->fail($automation->id);
        }

        $alert = OperationalAlert::query()->where('rule_key', 'resource-automation.collection')->sole();
        $this->assertSame('Hesap güncellemesi durdu · sc-domain:moximu.com', $alert->title);
        $this->assertStringContainsString('Moximu · Search Console hesabı "sc-domain:moximu.com"', (string) $alert->summary);
        $this->assertStringContainsString('Hesaba erişim yetkisi yok', (string) $alert->summary);
        $this->assertStringContainsString('ops@moximu.com kullanıcısına', (string) $alert->summary);
        $this->assertNoJargon((string) $alert->summary);

        Livewire::actingAs($this->admin)->test(NotificationBell::class)
            ->assertSee('Hesap güncellemesi durdu · sc-domain:moximu.com')
            ->assertSee('Neden önemli:')
            ->assertSee('Ne yapmalısın:')
            ->assertSee('Şimdi güncelle')
            ->assertSee(route('operator.asset.sources', ['assetId' => $asset->id]))
            ->assertDontSee('müdahale beklenir')
            ->call('runNow', $automation->id)
            ->assertSee('Güncelleme sıraya alındı');

        $automation->refresh();
        $this->assertNull($automation->collection_error);
        $this->assertNotNull($automation->next_collection_at);
    }

    public function test_repeated_collection_failures_name_the_account_and_explain_quota_in_turkish(): void
    {
        [$asset, $resource] = $this->searchConsoleAccount('Atlas Dental', 'atlasdental.com');
        foreach (range(1, 3) as $i) {
            $this->failedDataset($resource, $asset, CollectionErrorCategory::Quota, 'gsc_query_daily');
        }

        app(OperationalAlertEvaluator::class)->evaluate();

        $alert = OperationalAlert::query()->where('rule_key', 'collection_repeated_failure')->sole();
        $message = app(OperationalAlertExplainer::class)->explain($alert);
        $this->assertSame('Atlas Dental · sc-domain:atlasdental.com verisi çekilemiyor', $message->title);
        $this->assertStringContainsString('Son 1 saatte 3 veri çekimi başarısız oldu: Atlas Dental · sc-domain:atlasdental.com (Search Console arama sorguları)', $message->what);
        $this->assertStringContainsString('Google günlük istek kotası doldu', $message->what);
        $this->assertStringContainsString('yarın kendiliğinden devam eder', $message->action);
        $this->assertSame(route('operator.asset.sources', ['assetId' => $asset->id]), $message->linkUrl);
        $this->assertNoJargon($message->plainText().' '.$message->title);
        $this->assertNoJargon((string) $alert->summary);
    }

    public function test_aggregated_stale_alert_lists_five_accounts_then_plus_n_with_data_in_plain_words(): void
    {
        $rows = [];
        foreach (range(1, 7) as $i) {
            [$asset, $resource] = $this->searchConsoleAccount('Marka '.$i, 'site'.$i.'.com');
            $rows[] = ['asset_id' => $asset->id, 'resource_id' => $resource->id, 'dataset' => 'gsc_property_daily', 'state' => 'STALE'];
        }
        $alert = $this->observe('dataset_stale', 'dataset:stale', ['affected' => app(AlertSubjects::class)->describe($rows), 'affected_total' => 7]);

        $message = app(OperationalAlertExplainer::class)->explain($alert);
        $this->assertSame('7 hesabın verisi güncel değil', $message->title);
        $this->assertStringContainsString('7 hesapta veri zamanında yenilenmedi: Marka 1 · sc-domain:site1.com (Search Console günlük tıklamalar)', $message->what);
        $this->assertStringContainsString('Marka 5 · sc-domain:site5.com', $message->what);
        $this->assertStringNotContainsString('Marka 6 ·', $message->what);
        $this->assertStringContainsString(' +2.', $message->what);
        $this->assertNotSame('', $message->why);
        $this->assertSame(route('operator.portfolio.health', ['onlyProblems' => 1]), $message->linkUrl);
        $this->assertNoJargon($message->plainText().' '.$message->title);
    }

    public function test_single_account_that_lost_authorization_gets_a_reconnect_button(): void
    {
        [$asset, $resource] = $this->searchConsoleAccount('Adadent', 'adadent.com');
        $alert = $this->observe('dataset_stale', 'dataset:stale', [
            'affected' => app(AlertSubjects::class)->describe([['asset_id' => $asset->id, 'resource_id' => $resource->id, 'dataset' => 'gsc_property_daily', 'state' => 'ACTION_REQUIRED']]),
            'affected_total' => 1,
        ]);

        $message = app(OperationalAlertExplainer::class)->explain($alert);
        $this->assertSame('Adadent · sc-domain:adadent.com verisi güncel değil', $message->title);
        $this->assertStringContainsString('Google bağlantısının izni sona ermiş', $message->what);
        $this->assertStringContainsString('Google bağlantısını yenileyin', $message->action);
        $this->assertSame(route('operator.asset.sources', ['assetId' => $asset->id]), $message->linkUrl);
        $this->assertSame('Yeniden bağlan', $message->button['label'] ?? null);
        $this->assertSame(route('integrations.google.authorize', ['integration' => $resource->integration_id]), $message->button['url'] ?? null);
    }

    public function test_old_english_rows_read_in_turkish(): void
    {
        $alert = OperationalAlert::query()->forceCreate([
            'semantic_key' => str_repeat('a', 64), 'rule_key' => 'collection_repeated_failure', 'rule_version' => 1,
            'rule_type' => OperationalAlertRuleType::CollectionRepeatedFailure, 'signal_family' => OperationalSignalFamily::Collection,
            'severity' => OperationalAlertSeverity::Warning, 'state' => OperationalAlertState::Open, 'scope_type' => 'SYSTEM',
            'scope_key' => 'collection:failures', 'title' => 'Repeated collection failures',
            'summary' => '3 failed CollectionRun(s) in the last 3600s', 'observed' => ['failure_count' => 3, 'window_seconds' => 3600],
            'first_observed_at' => now(), 'last_observed_at' => now(), 'opened_at' => now(),
        ]);

        $message = app(OperationalAlertExplainer::class)->explain($alert);
        $this->assertSame('Veri çekimleri üst üste başarısız oluyor', $message->title);
        $this->assertStringStartsWith('Son 1 saatte 3 veri çekimi başarısız oldu.', $message->what);
        $this->assertNoJargon($message->plainText().' '.$message->title);
    }

    public function test_a_condition_that_comes_back_keeps_one_bell_row_with_a_counter_and_resolved_ones_disappear(): void
    {
        $this->travelTo(now()->setDate(2026, 9, 24)->setTime(9, 0));
        $alert = $this->observe('dataset_stale', 'dataset:stale', ['stale_or_blocked_count' => 3]);
        $this->assertSame(1, UserNotification::query()->count());
        $reads = app(NotificationReadService::class);
        $this->assertSame(1, $reads->unreadCount($this->admin));

        // Read, resolved, back within the quiet period: same row, updated, stays read.
        $row = UserNotification::query()->sole();
        $row->forceFill(['read_at' => now()])->save();
        $lifecycle = app(OperationalAlertLifecycleService::class);
        $lifecycle->resolveIfActive('dataset_stale', 'SYSTEM', 'dataset:stale');
        $this->assertSame([], $reads->forUser($this->admin), 'a resolved condition leaves the bell');
        $this->travel(3)->hours();
        $this->observe('dataset_stale', 'dataset:stale', ['stale_or_blocked_count' => 3]);
        $this->assertSame(1, UserNotification::query()->whereNull('archived_at')->count());
        $this->assertNotNull(UserNotification::query()->sole()->read_at);
        $this->assertSame(2, $alert->fresh()->occurrence_count);

        // Back again after the quiet period: the same row pings again (unread, on top).
        $lifecycle->resolveIfActive('dataset_stale', 'SYSTEM', 'dataset:stale');
        $this->travel(2)->days();
        $this->observe('dataset_stale', 'dataset:stale', ['stale_or_blocked_count' => 3]);
        $this->assertSame(1, UserNotification::query()->whereNull('archived_at')->count());
        $this->assertNull(UserNotification::query()->sole()->read_at);
        $this->assertSame(1, $reads->unreadCount($this->admin));

        $items = $reads->forUser($this->admin);
        $this->assertCount(1, $items);
        $this->assertSame('3. kez · ilk 24 Eyl', $items[0]['operator_message']['repeat_label']);
        Livewire::actingAs($this->admin)->test(NotificationBell::class)->assertSee('3. kez · ilk 24 Eyl');
    }

    public function test_duplicate_rows_of_older_code_show_once_and_are_read_together(): void
    {
        $alert = $this->observe('dataset_stale', 'dataset:stale', ['stale_or_blocked_count' => 2]);
        // Older code emitted a new notification on every reopen.
        app(DomainEventEmitter::class)->emit([
            'event_type' => DomainEventType::OperationalAlertOpened, 'actor_kind' => DomainEventActorKind::System,
            'subject_kind' => DomainEventSubjectKind::OperationalAlert, 'subject_id' => (int) $alert->id,
            'payload' => ['recipient_user_ids' => [$this->admin->id], 'title' => 'Datasets reported STALE / BLOCKED by Prompt27'],
        ], 'legacy-duplicate');
        $this->assertSame(2, UserNotification::query()->count());

        $reads = app(NotificationReadService::class);
        $this->assertCount(1, $reads->forUser($this->admin));
        $this->assertSame(1, $reads->unreadCount($this->admin));

        Livewire::actingAs($this->admin)->test(NotificationBell::class)->call('markRead', (string) $reads->forUser($this->admin)[0]['id']);
        $this->assertSame(0, $reads->unreadCount($this->admin));
    }

    public function test_command_center_and_system_health_carry_the_explanation_and_the_exact_link(): void
    {
        [$asset, $resource, $automation] = $this->searchConsoleAccount('Moximu', 'moximu.com');
        $this->failedDataset($resource, $asset, CollectionErrorCategory::Provider5xx, 'gsc_property_daily');
        app(ResourceAutomationService::class)->fail($automation->id, 'collection_failed');
        app(ResourceAutomationService::class)->fail($automation->id, 'collection_failed');
        app(ResourceAutomationService::class)->fail($automation->id, 'collection_failed');

        $item = app(CommandCenter::class)->items(['source' => 'system'])->sole();
        $this->assertSame('system:resource-automation.collection', $item['topic']);
        $this->assertSame('Hesap güncellemesi durdu', $item['topic_label']);
        $this->assertSame($asset->id, $item['asset_id']);
        $this->assertSame(route('operator.asset.sources', ['assetId' => $asset->id]), $item['url']);
        $this->assertStringContainsString('Google tarafında geçici bir hata oluştu', (string) $item['detail']);
        $this->assertNotEmpty($item['why']);
        $this->assertSame(['label' => 'Şimdi güncelle', 'run_now' => $automation->id], $item['button']);

        Livewire::actingAs($this->admin)->test(SystemHealthPage::class)
            ->assertSee('Ne oldu:')
            ->assertSee('Ne yapmalısın:')
            ->assertSee('Varlığın veri kaynaklarını aç')
            ->call('runNowAutomation', $automation->id)
            ->assertSee('Güncelleme sıraya alındı');
    }

    public function test_name_list_and_short_date_helpers(): void
    {
        $this->assertSame('a, b, c, d, e +2', OperatorMessage::nameList(['a', 'b', 'c', 'd', 'e', 'f', 'g']));
        $this->assertSame('a, b', OperatorMessage::nameList(['a', 'b', 'a', '']));
        $message = new OperatorMessage('t', 'w', 'y', 'x', occurrences: 3, firstSeen: now()->setDate(2026, 9, 24));
        $this->assertSame('3. kez · ilk 24 Eyl', $message->repeatLabel());
        $this->assertSame('', (new OperatorMessage('t', 'w', 'y', 'x'))->repeatLabel());
    }

    private function assertNoJargon(string $text): void
    {
        foreach (self::JARGON as $word) {
            $this->assertStringNotContainsStringIgnoringCase($word, $text, 'jargon "'.$word.'" in: '.$text);
        }
    }

    /**
     * @param  array<string, mixed>  $observed
     */
    private function observe(string $rule, string $scope, array $observed): OperationalAlert
    {
        return app(OperationalAlertLifecycleService::class)->observeCondition(
            ruleKey: $rule, ruleVersion: 1, ruleType: OperationalAlertRuleType::DatasetStale, family: OperationalSignalFamily::Dataset,
            severity: OperationalAlertSeverity::Warning, scopeType: 'SYSTEM', scopeKey: $scope, title: 'x', summary: null, observed: $observed,
        );
    }

    /** @return array{0: DigitalAsset, 1: CoreExternalResource, 2: ResourceAutomation} */
    private function searchConsoleAccount(string $brandName, string $domain, ?string $email = null): array
    {
        $integration = CoreIntegration::query()->where('provider', 'google')->first()
            ?? CoreIntegration::factory()->google()->create(['config' => $email !== null ? ['account_email' => $email] : []]);
        $brand = Brand::factory()->create(['name' => $brandName]);
        $asset = DigitalAsset::factory()->create(['brand_id' => $brand->id, 'type' => 'website', 'name' => $domain, 'domain' => $domain]);
        $resource = CoreExternalResource::factory()->create([
            'integration_id' => $integration->id, 'resource_type' => 'search_console', 'external_id' => 'sc-domain:'.$domain, 'display_name' => 'sc-domain:'.$domain,
        ]);
        CoreAssetBinding::factory()->create(['digital_asset_id' => $asset->id, 'external_resource_id' => $resource->id, 'capability' => 'search_console']);
        $automation = ResourceAutomation::query()->create(['external_resource_id' => $resource->id, 'collection_enabled' => true]);

        return [$asset, $resource, $automation];
    }

    private function failedDataset(CoreExternalResource $resource, DigitalAsset $asset, CollectionErrorCategory $category, string $dataset): void
    {
        $run = CollectionRun::factory()->create(['status' => CollectionRunStatus::Failed, 'finished_at' => now(), 'digital_asset_id' => $asset->id, 'brand_id' => $asset->brand_id]);
        $resourceRun = CollectionResourceRun::factory()->create([
            'collection_run_id' => $run->id, 'provider_or_source' => 'GSC', 'external_resource_id' => $resource->id,
            'digital_asset_id' => $asset->id, 'status' => CollectionRunStatus::Failed,
        ]);
        CollectionDatasetRun::factory()->create([
            'collection_run_id' => $run->id, 'collection_resource_run_id' => $resourceRun->id, 'provider_or_source' => 'GSC',
            'dataset_contract_id' => $dataset, 'status' => CollectionRunStatus::Failed, 'error_category' => $category,
        ]);
    }
}
