<?php

namespace Tests\Feature\Observability;

use App\Enums\Collection\CollectionErrorCategory;
use App\Enums\Collection\CollectionRunStatus;
use App\Enums\Observability\OperationalAlertState;
use App\Models\Collection\CollectionDatasetRun;
use App\Models\Collection\CollectionResourceRun;
use App\Models\Collection\CollectionRun;
use App\Models\CoreAssetBinding;
use App\Models\CoreExternalResource;
use App\Models\CoreIntegration;
use App\Models\Observability\OperationalAlert;
use App\Models\ResourceAutomation;
use App\Services\Integrations\ResourceAutomationService;
use App\Services\Observability\ErrorTriage;
use App\Services\Observability\OperationalAlertExplainer;
use App\Services\Verification\LiveVerifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * "Hesap güncellemesi durdu" after a failed collection run names the data that fell, the provider's error code and
 * answer; a provider 5xx that comes back after every daily retry is no longer called transient and goes to the developer.
 */
final class StoppedAccountCauseTest extends TestCase
{
    use RefreshDatabase;

    private const string REDUCE = "Please reduce the amount of data you're asking for, then retry your request · [meta-error-v2 · http 500 · code 1]";

    private CoreExternalResource $resource;

    private ResourceAutomation $automation;

    protected function setUp(): void
    {
        parent::setUp();
        $this->freezeTime();
        app()->setLocale('tr');
        config(['moxdop-resource-automation.queue_connection' => 'database']);
        Queue::fake();
        $integration = CoreIntegration::factory()->meta()->create(['status' => CoreIntegration::STATUS_ACTIVE]);
        $this->resource = CoreExternalResource::factory()->create([
            'integration_id' => $integration->id, 'provider' => 'meta', 'resource_type' => 'meta_ads', 'external_id' => 'act_5550001',
            'display_name' => 'Obezite ve Estetik', 'status' => CoreExternalResource::STATUS_AVAILABLE,
        ]);
        // Factory defaults: an active asset of a brand with an active customer.
        CoreAssetBinding::factory()->create(['external_resource_id' => $this->resource->id, 'capability' => 'meta_ads']);
        $this->automation = ResourceAutomation::query()->create([
            'external_resource_id' => $this->resource->id, 'collection_enabled' => true, 'collection_status' => 'waiting',
        ]);
    }

    public function test_the_stop_alert_names_the_failed_dataset_its_code_and_the_meta_answer(): void
    {
        $this->automation->update(['collection_failures' => 2]);
        $this->finishFailedRun([
            ['meta_ad_daily', CollectionErrorCategory::Provider5xx, 'META_HTTP_1', self::REDUCE],
            // Consequences of another failure: never the cause, even when newer.
            ['meta_typed_action_daily', CollectionErrorCategory::Timeout, 'DEPENDENCY_FAILED', 'A required dataset did not complete; dependent work cannot run.'],
            ['meta_adset_daily', CollectionErrorCategory::Unknown, 'INTERRUPTED_WORKER', 'Worker repeatedly stopped at the same checkpoint; inspect worker logs.'],
        ]);

        $alert = $this->stopAlert();
        $this->assertSame(['attention', 'collection_failed'], [$this->automation->fresh()->collection_status, $this->automation->fresh()->collection_error]);
        $this->assertSame('provider_5xx', $alert->observed['error_category']);
        $this->assertSame('meta_ad_daily', $alert->observed['dataset']);
        $this->assertSame('META_HTTP_1', $alert->observed['error_code']);
        $this->assertStringContainsString('meta_ad_daily', $alert->observed['safe_error']);
        $this->assertStringContainsString('META_HTTP_1', $alert->observed['safe_error']);
        $this->assertSame(['meta_ad_daily'], $alert->observed['affected'][0]['datasets']);
        $this->assertSame('provider_5xx', $alert->observed['affected'][0]['error_category']);

        $message = app(OperationalAlertExplainer::class)->explain($alert);
        $this->assertStringContainsString('Neden: Meta tarafında geçici bir hata oluştu (Meta Ads reklam sonuçları · META_HTTP_1).', $message->what);
        $this->assertStringContainsString('Meta yanıtı: "Please reduce the amount of data', $message->what);
        $this->assertStringContainsString('Sistem her gün kendiliğinden yeniden dener', $message->why);
        $this->assertSame(ErrorTriage::AUTO, app(ErrorTriage::class)->bucket($alert));
    }

    public function test_a_5xx_that_stops_the_account_again_after_the_daily_retry_is_not_called_transient(): void
    {
        $this->travelTo(now()->setDate(2026, 10, 1)->setTime(3, 55));
        $service = app(ResourceAutomationService::class);
        foreach (range(1, 3) as $attempt) {
            $this->finishFailedRun([['meta_ad_daily', CollectionErrorCategory::Provider5xx, 'META_HTTP_1', self::REDUCE]]);
        }
        $alert = $this->stopAlert();
        $this->assertSame(1, (int) $alert->observation_count);
        $this->assertSame(ErrorTriage::AUTO, app(ErrorTriage::class)->bucket($alert));
        $this->assertStringContainsString('geçici', app(OperationalAlertExplainer::class)->explain($alert)->what);

        // The next morning's retry fails three times again with the same answer.
        $this->travel(1)->day();
        $this->assertSame(1, $service->retryStopped()['retried']);
        foreach (range(1, 3) as $attempt) {
            $this->finishFailedRun([['meta_ad_daily', CollectionErrorCategory::Provider5xx, 'META_HTTP_1', self::REDUCE]]);
        }

        $alert = $this->stopAlert();
        $this->assertSame(OperationalAlertState::Open, $alert->state);
        $this->assertSame(2, (int) $alert->observation_count);
        $this->assertSame(ErrorTriage::CODE, app(ErrorTriage::class)->bucket($alert));
        $this->assertSame(ErrorTriage::AUTO, ErrorTriage::cause($alert), 'the bell decision at opening is unchanged');
        $message = app(OperationalAlertExplainer::class)->explain($alert);
        $this->assertStringContainsString('META_HTTP_1', $message->what);
        $this->assertStringContainsString('Hesap 2 kez durdu; her günlük yeniden denemede aynı hata tekrar ediyor (ilk: 1 Eki), beklemekle düzelmiyor.', $message->what);
        $this->assertStringContainsString('Meta sunucu hatası döndürüyor (Meta Ads reklam sonuçları · META_HTTP_1)', $message->what);
        $this->assertStringNotContainsString('geçici', $message->what);
        $this->assertStringNotContainsString('kendiliğinden', $message->what);
        $this->assertStringNotContainsString('Sistem her gün', $message->why);
        $this->assertStringContainsString('Tekrar denemek işe yaramıyor; yazılım ekibine bildirin', $message->action);
        $this->assertStringContainsString('Hesap 2 kez durdu', (string) $alert->summary, 'the stored text reads the same');
        $items = collect(app(ErrorTriage::class)->groups()[ErrorTriage::CODE])->flatMap(fn (array $group): array => $group['items']);
        $this->assertSame([(int) $alert->id], $items->pluck('id')->all());
    }

    public function test_a_first_stop_is_not_repeating_however_old_it_is(): void
    {
        $this->automation->update(['collection_failures' => 2]);
        $this->finishFailedRun([['meta_ad_daily', CollectionErrorCategory::Provider5xx, 'META_HTTP_2', 'Service temporarily unavailable · [meta-error-v2 · http 500 · code 2]']]);
        $this->travel(ErrorTriage::ESCALATE_HOURS + 1)->hours();

        $alert = $this->stopAlert();
        $this->assertSame(1, (int) $alert->observation_count);
        $this->assertFalse(ErrorTriage::repeatsEveryRound($alert));
        $this->assertSame(ErrorTriage::YOU, app(ErrorTriage::class)->bucket($alert));
    }

    public function test_a_repeated_stop_without_a_provider_error_is_not_sent_to_the_developer(): void
    {
        $this->automation->update(['collection_failures' => 2]);
        $this->finishFailedRun([['meta_ad_daily', CollectionErrorCategory::RateLimit, 'META_RATE_LIMIT_4', 'Application request limit reached']]);
        $this->automation->refresh()->update(['collection_failures' => 2]);
        $this->finishFailedRun([['meta_ad_daily', CollectionErrorCategory::RateLimit, 'META_RATE_LIMIT_4', 'Application request limit reached']]);

        $alert = $this->stopAlert();
        $this->assertSame(2, (int) $alert->observation_count);
        $this->assertFalse(ErrorTriage::repeatsEveryRound($alert));
        $this->assertSame(ErrorTriage::AUTO, app(ErrorTriage::class)->bucket($alert));
    }

    public function test_a_repeated_5xx_of_an_account_the_live_check_finds_closed_stays_the_operators(): void
    {
        foreach (range(1, 2) as $round) {
            $this->automation->refresh()->update(['collection_failures' => 2]);
            $this->finishFailedRun([['meta_ad_daily', CollectionErrorCategory::Provider5xx, 'META_HTTP_2', 'Service temporarily unavailable']]);
        }
        DB::table('live_checks')->insert(['check_key' => 'meta:'.$this->resource->id, 'provider' => 'meta', 'capability' => 'meta_ads',
            'subject_type' => 'external_resource', 'subject_id' => $this->resource->id, 'label' => 'Meta Ads · X', 'status' => LiveVerifier::FAIL,
            'message' => 'Hesap okunuyor ama reklam yayınlayamaz: DISABLED.', 'checked_at' => now()]);

        $alert = $this->stopAlert();
        $this->assertSame(2, (int) $alert->observation_count);
        $this->assertFalse(ErrorTriage::repeatsEveryRound($alert));
        $this->assertSame(ErrorTriage::YOU, app(ErrorTriage::class)->bucket($alert));
        $this->assertStringNotContainsString('Tekrar denemek işe yaramıyor', app(OperationalAlertExplainer::class)->explain($alert)->action);
    }

    public function test_the_cause_is_the_dataset_whose_category_decided_the_stop(): void
    {
        $this->finishFailedRun([
            ['meta_campaign_daily', CollectionErrorCategory::Authentication, 'META_AUTH_190', 'Error validating access token · [meta-error-v2 · http 400 · code 190]'],
            ['meta_ad_daily', CollectionErrorCategory::Provider5xx, 'META_HTTP_2', 'Service temporarily unavailable'],
        ]);

        $alert = $this->stopAlert();
        $this->assertSame('reconnect', $this->automation->fresh()->collection_error);
        $this->assertSame('meta_campaign_daily', $alert->observed['dataset']);
        $this->assertSame('META_AUTH_190', $alert->observed['error_code']);
        $this->assertStringContainsString('(Meta Ads kampanya sonuçları · META_AUTH_190)', app(OperationalAlertExplainer::class)->explain($alert)->what);
    }

    /**
     * A finished, failed collection run of the account with these failed datasets (in this order), picked up by the
     * next scheduler tick (reconcile).
     *
     * @param  list<array{0: string, 1: CollectionErrorCategory, 2: ?string, 3: ?string}>  $datasets
     */
    private function finishFailedRun(array $datasets): void
    {
        $run = CollectionRun::factory()->create(['status' => CollectionRunStatus::Failed, 'finished_at' => now()]);
        $resourceRun = CollectionResourceRun::factory()->create([
            'collection_run_id' => $run->id, 'provider_or_source' => 'META_ADS', 'external_resource_id' => $this->resource->id,
            'status' => CollectionRunStatus::Failed,
        ]);
        foreach ($datasets as [$dataset, $category, $code, $message]) {
            CollectionDatasetRun::factory()->create([
                'collection_run_id' => $run->id, 'collection_resource_run_id' => $resourceRun->id, 'provider_or_source' => 'META_ADS',
                'dataset_contract_id' => $dataset, 'status' => CollectionRunStatus::Failed,
                'error_category' => $category, 'error_code' => $code, 'error_message' => $message,
            ]);
        }
        $this->automation->refresh()->update(['collection_status' => 'collecting', 'collection_run_id' => $run->id]);

        app(ResourceAutomationService::class)->tick();
    }

    private function stopAlert(): OperationalAlert
    {
        return OperationalAlert::query()->where('rule_key', 'resource-automation.collection')->where('scope_key', (string) $this->resource->id)->sole();
    }
}
