<?php

namespace Tests\Feature\Gbp;

use App\Exceptions\Integrations\GoogleBusinessProfileRequestException;
use App\Models\Brand;
use App\Models\CoreAssetBinding;
use App\Models\CoreExternalResource;
use App\Models\CoreIntegration;
use App\Models\CoreIntegrationCredential;
use App\Models\DigitalAsset;
use App\Models\Observability\OperationalAlert;
use App\Models\ResourceAutomation;
use App\Models\Run;
use App\Services\Integrations\Google\GoogleBusinessProfileBoundCollector;
use App\Services\Integrations\ResourceAutomationService;
use App\Services\Observability\ErrorTriage;
use App\Services\Observability\OperationalAlertExplainer;
use App\Support\Operator\CollectionErrorExplainer;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * A stopped Business Profile collection says why in its alert (Yetki yok, API kapalı, konum bulunamadı …) instead of
 * "beklenmeyen bir hata", never claims three attempts, and a software error during the collection reaches the error
 * list while Google's refusals do not.
 */
final class GbpCollectionFailureCauseTest extends TestCase
{
    use RefreshDatabase;

    private const string FORBIDDEN = 'provider request failed with HTTP 403. PERMISSION_DENIED The caller does not have permission';

    private const string DISABLED = 'provider request failed with HTTP 403. PERMISSION_DENIED Business Profile Performance API has not been used in project 1 before or it is disabled.';

    private CoreExternalResource $resource;

    private ResourceAutomation $automation;

    /** HTTP status of the daily metrics calls in the end-to-end runs. */
    private int $metricsStatus = 403;

    protected function setUp(): void
    {
        parent::setUp();
        app()->setLocale('tr');
        Queue::fake();
        config(['moxdop.google.client_id' => 'cid', 'moxdop.google.client_secret' => 'csecret']);
        $integration = CoreIntegration::factory()->google()->create(['status' => CoreIntegration::STATUS_ACTIVE]);
        CoreIntegrationCredential::factory()->provider()->create(['integration_id' => $integration->id, 'encrypted_payload' => ['client_id' => 'cid', 'client_secret' => 'csecret']]);
        CoreIntegrationCredential::factory()->authorization()->create(['integration_id' => $integration->id,
            'encrypted_payload' => ['access_token' => 'a', 'refresh_token' => 'r', 'scope' => 'https://www.googleapis.com/auth/business.manage'], 'expires_at' => now()->addHour()]);
        $asset = DigitalAsset::factory()->create(['brand_id' => Brand::factory()->create(['name' => 'Atlas Dental'])->id,
            'type' => 'google_business_profile', 'status' => 'active', 'name' => 'Atlas Çankaya']);
        $this->resource = CoreExternalResource::factory()->create(['integration_id' => $integration->id, 'resource_type' => 'google_business_profile',
            'external_id' => 'locations/22', 'display_name' => 'Atlas Çankaya', 'status' => CoreExternalResource::STATUS_AVAILABLE]);
        CoreAssetBinding::factory()->create(['digital_asset_id' => $asset->id, 'external_resource_id' => $this->resource->id,
            'capability' => 'google_business_profile', 'status' => CoreAssetBinding::STATUS_ACTIVE]);
        $this->automation = ResourceAutomation::query()->create(['external_resource_id' => $this->resource->id, 'collection_enabled' => true, 'collection_status' => 'planning']);
    }

    public function test_a_refused_location_names_the_missing_access_and_does_not_claim_three_attempts(): void
    {
        $alert = $this->finishRunWith([
            'gbp_location' => ['status' => 'unavailable', 'reason' => 'gbp_location '.self::FORBIDDEN],
            'gbp_performance_daily' => ['status' => 'unavailable', 'reason' => 'No GBP Performance metric could be collected. gbp_performance_daily '.self::FORBIDDEN],
        ], 'failed');

        $automation = $this->automation->fresh();
        $this->assertSame('attention', $automation->collection_status);
        $this->assertSame('collection_failed', $automation->collection_error);
        $this->assertSame('authorization', $alert->observed['error_category']);
        $this->assertSame('authorization', $alert->observed['affected'][0]['error_category']);
        $this->assertStringContainsString('gbp_location: gbp_location provider request failed with HTTP 403', $alert->observed['safe_error']);

        $summary = (string) $alert->summary;
        $this->assertStringContainsString('Son otomatik güncelleme başarısız olduğu için durduruldu', $summary);
        $this->assertStringContainsString('Neden: Yetki yok: hesabın bu konumda sahip/yönetici olması', $summary);
        $this->assertStringNotContainsString('3 kez', $summary);
        $this->assertStringNotContainsString('beklenmeyen', $summary);
        foreach (['failed', 'dataset', 'HTTP'] as $raw) {
            $this->assertStringNotContainsStringIgnoringCase($raw, $summary, 'Google\'s raw error stays in observed, the text is plain Turkish');
        }
        $message = app(OperationalAlertExplainer::class)->explain($alert);
        $this->assertStringContainsString('okuma yetkisi vermesini isteyin', $message->action);
        $this->assertSame(['label' => 'Şimdi güncelle', 'run_now' => $this->automation->id], $message->button);
        $this->assertSame(ErrorTriage::YOU, ErrorTriage::cause($alert), 'missing access needs a person, it does not heal by itself');
    }

    public function test_a_disabled_api_is_explained_as_an_api_to_enable_not_as_missing_access(): void
    {
        $alert = $this->finishRunWith([
            'gbp_location' => ['status' => 'available'],
            'gbp_performance_daily' => ['status' => 'unavailable', 'reason' => 'No GBP Performance metric could be collected. gbp_performance_daily '.self::DISABLED],
        ], 'partial');

        $this->assertSame('service_disabled', $alert->observed['error_category']);
        $summary = (string) $alert->summary;
        $this->assertStringContainsString('Business Profile API kapalı', $summary);
        $this->assertStringContainsString('API Kitaplığı', $summary);
        $this->assertStringNotContainsString('Yetki yok', $summary);
        $this->assertStringNotContainsString('beklenmeyen', $summary);
        $this->assertSame(ErrorTriage::YOU, ErrorTriage::cause($alert));
    }

    public function test_only_the_core_datasets_name_the_cause(): void
    {
        // An optional dataset's disabled API is shown on the profile page; the run failed because the location is gone.
        $alert = $this->finishRunWith([
            'gbp_location' => ['status' => 'unavailable', 'reason' => 'gbp_location provider request failed with HTTP 404. NOT_FOUND Requested entity was not found.'],
            'gbp_performance_daily' => ['status' => 'unavailable', 'reason' => 'No GBP Performance metric could be collected. gbp_performance_daily provider request failed with HTTP 404. NOT_FOUND'],
            'gbp_reviews' => ['status' => 'unavailable', 'reason' => 'GBP account context could not be resolved for v4 reviews/media/posts. My Business Account Management API has not been used in project 1. SERVICE_DISABLED'],
        ], 'failed');

        $this->assertSame('not_found', $alert->observed['error_category']);
        $this->assertStringNotContainsString('gbp_reviews', $alert->observed['safe_error']);
        $this->assertStringContainsString('Neden: Konum bulunamadı', (string) $alert->summary);
    }

    public function test_with_two_failed_core_datasets_the_reason_and_the_fix_name_the_same_cause(): void
    {
        // The location is gone (404) and the performance calls hit MoxDOP's own pacing: the category is not_found, so
        // "Neden" must not say "istek sınırı … kendiliğinden" next to a fix that asks for access.
        $alert = $this->finishRunWith([
            'gbp_location' => ['status' => 'unavailable', 'reason' => 'gbp_location provider request failed with HTTP 404. NOT_FOUND Requested entity was not found.'],
            'gbp_performance_daily' => ['status' => 'unavailable', 'reason' => 'No GBP Performance metric could be collected. GBP request pacing limit reached; retry on the next collection.'],
        ], 'failed');

        $this->assertSame('not_found', $alert->observed['error_category']);
        $message = app(OperationalAlertExplainer::class)->explain($alert);
        $this->assertStringContainsString('Neden: Konum bulunamadı', $message->what);
        $this->assertStringNotContainsString('istek sınırı', $message->what);
        $this->assertSame(CollectionErrorExplainer::explain('not_found')['fix'], $message->action);
    }

    public function test_a_cause_without_a_known_category_still_reads_plainly_and_a_later_success_resolves_the_alert(): void
    {
        $alert = $this->finishRunWith(['gbp_location' => ['status' => 'unavailable', 'reason' => 'GBP dataset time budget reached; partial data retained. Retry this location.'],
            'gbp_performance_daily' => ['status' => 'unavailable']], 'failed');

        $this->assertArrayNotHasKey('error_category', $alert->observed);
        $this->assertStringContainsString('gbp_performance_daily: veri yok', $alert->observed['safe_error']);
        $this->assertStringContainsString('Çekim beklenmeyen bir hatayla durdu', (string) $alert->summary);
        $this->assertStringNotContainsString('3 kez', (string) $alert->summary);

        $this->replan();
        $this->finishRunWith(['gbp_location' => ['status' => 'available'], 'gbp_performance_daily' => ['status' => 'available']], 'completed');
        $this->assertFalse($alert->fresh()->isActive());
    }

    public function test_other_sources_still_say_they_stopped_after_three_failures(): void
    {
        $resource = CoreExternalResource::factory()->create(['integration_id' => $this->resource->integration_id, 'resource_type' => 'search_console',
            'external_id' => 'sc-domain:atlasdental.com', 'display_name' => 'sc-domain:atlasdental.com']);
        CoreAssetBinding::factory()->create(['external_resource_id' => $resource->id, 'capability' => 'search_console']);
        $automation = ResourceAutomation::query()->create(['external_resource_id' => $resource->id, 'collection_enabled' => true]);
        foreach (range(1, 3) as $attempt) {
            app(ResourceAutomationService::class)->fail($automation->id);
        }

        $alert = OperationalAlert::query()->where('rule_key', 'resource-automation.collection')->where('scope_key', (string) $resource->id)->sole();
        $this->assertStringContainsString('üst üste 3 kez başarısız', (string) $alert->summary);
    }

    public function test_end_to_end_a_refused_performance_api_reaches_the_alert_and_is_not_reported_as_a_software_error(): void
    {
        Exceptions::fake();
        $this->fakeGoogle();

        $alert = $this->collectUntilFinished();

        $performance = (array) data_get(Run::query()->findOrFail($this->automation->fresh()->gbp_run_id)->metadata, 'datasets.gbp_performance_daily');
        $this->assertSame('unavailable', $performance['status']);
        $this->assertStringContainsString('HTTP 403', (string) $performance['reason'], 'the metric errors carry Google\'s answer into the dataset reason');
        $this->assertSame('authorization', $alert->observed['error_category']);
        $this->assertStringContainsString('Yetki yok', (string) $alert->summary);
        Exceptions::assertNotReported(GoogleBusinessProfileRequestException::class);
        Exceptions::assertNothingReported();
    }

    public function test_end_to_end_a_software_error_while_saving_is_reported(): void
    {
        Exceptions::fake();
        $this->metricsStatus = 200;
        $this->fakeGoogle();
        Schema::drop('gbp_performance_daily');

        $alert = $this->collectUntilFinished();

        Exceptions::assertReported(QueryException::class);
        Exceptions::assertNotReported(GoogleBusinessProfileRequestException::class);
        $this->assertArrayNotHasKey('error_category', $alert->observed, 'a software error is not dressed up as a Google refusal');
    }

    /** @return array<string, array{0: string, 1: ?string}> */
    public static function errorTexts(): array
    {
        return [
            'disabled api (403)' => ['gbp_performance_daily: '.self::DISABLED, 'service_disabled'],
            'service disabled reason' => ['gbp_place_actions: HTTP 403. PERMISSION_DENIED SERVICE_DISABLED', 'service_disabled'],
            'expired token' => ['gbp_location: gbp_location provider request failed with HTTP 401. UNAUTHENTICATED', 'authentication'],
            'refresh failed' => ['gbp_location: Google access token refresh failed.', 'authentication'],
            'connection unusable' => ['gbp_location: Google Integration authorization is not usable (revoked).', 'authentication'],
            'scope missing' => ['gbp_location: Google Connector scope required for google_business_profile.', 'authentication'],
            'no access' => ['gbp_location: gbp_location '.self::FORBIDDEN, 'authorization'],
            'location gone' => ['gbp_location: gbp_location provider request failed with HTTP 404. NOT_FOUND', 'not_found'],
            'rate limited' => ['gbp_location: gbp_location provider request failed with HTTP 429. RESOURCE_EXHAUSTED', 'rate_limit'],
            'own pacing' => ['gbp_location: GBP request pacing limit reached; retry on the next collection.', 'rate_limit'],
            'google outage' => ['gbp_location: gbp_location provider request failed with HTTP 503. UNAVAILABLE', 'provider_5xx'],
            'server error' => ['gbp_location: gbp_location provider request failed with HTTP 500.', 'provider_5xx'],
            'network' => ['gbp_location: Google API network failure.', 'network'],
            'bad request is not guessed' => ['gbp_location: gbp_location provider request failed with HTTP 400. INVALID_ARGUMENT', null],
            'unknown' => ['gbp_location: GBP dataset time budget reached; partial data retained.', null],
            'empty' => ['', null],
        ];
    }

    #[DataProvider('errorTexts')]
    public function test_error_text_maps_to_a_category_the_explainer_knows(string $error, ?string $category): void
    {
        $this->assertSame($category, GoogleBusinessProfileBoundCollector::errorCategory($error));
        if ($category !== null) {
            $this->assertNotSame('unknown', CollectionErrorExplainer::normalize($category), $category.' must have a plain explanation');
        }
    }

    public function test_a_disabled_api_needs_a_person(): void
    {
        $explained = CollectionErrorExplainer::explain('service_disabled');

        $this->assertSame('grant_access', $explained['kind']);
        $this->assertStringContainsString('API Kitaplığı', $explained['fix']);
    }

    /**
     * One automatic collection whose (fake) collector ends the run with these datasets.
     *
     * @param  array<string, array<string, string>>  $datasets
     */
    private function finishRunWith(array $datasets, string $status): OperationalAlert
    {
        app()->instance(GoogleBusinessProfileBoundCollector::class, new class($datasets, $status)
        {
            /** @param  array<string, array<string, string>>  $datasets */
            public function __construct(private array $datasets, private string $status) {}

            public function collectResourceStep(CoreExternalResource $resource, Run $run): Run
            {
                $missing = collect($this->datasets)->reject(fn (array $d): bool => in_array($d['status'], ['available', 'partial'], true))
                    ->map(fn (array $d, string $key): string => $key.': '.($d['reason'] ?? 'veri yok'))->values()->all();
                $run->update(['status' => $this->status, 'finished_at' => now(), 'metadata' => array_merge($run->metadata ?? [], [
                    'datasets' => $this->datasets, 'safe_error' => $missing !== [] ? implode(' · ', $missing) : null])]);

                return $run->fresh();
            }
        });
        app(ResourceAutomationService::class)->collect($this->automation->id);

        return OperationalAlert::query()->where('rule_key', 'resource-automation.collection')->where('scope_key', (string) $this->resource->id)->sole();
    }

    /** Runs the real collector one dataset per turn, as the worker does, until the run finishes. */
    private function collectUntilFinished(): OperationalAlert
    {
        $service = app(ResourceAutomationService::class);
        foreach (range(1, 20) as $turn) {
            $this->replan();
            $service->collect($this->automation->id);
            if ($this->automation->fresh()->collection_status !== 'waiting') {
                break;
            }
        }
        $this->assertSame('attention', $this->automation->fresh()->collection_status);

        return OperationalAlert::query()->where('rule_key', 'resource-automation.collection')->where('scope_key', (string) $this->resource->id)->sole();
    }

    /** The scheduler's hand-off: the account is due again. */
    private function replan(): void
    {
        ResourceAutomation::query()->whereKey($this->automation->id)->update(['collection_status' => 'planning']);
    }

    private function fakeGoogle(): void
    {
        Http::fake(function (Request $request) {
            $url = $request->url();

            return match (true) {
                str_contains($url, 'getDailyMetricsTimeSeries') => $this->metricsStatus === 200
                    ? Http::response(['timeSeries' => ['datedValues' => [['date' => ['year' => 2026, 'month' => 9, 'day' => 1], 'value' => '5']]]])
                    : Http::response(['error' => ['status' => 'PERMISSION_DENIED', 'message' => 'The caller does not have permission']], $this->metricsStatus),
                str_contains($url, 'mybusinessbusinessinformation.googleapis.com/v1/locations/22?') || str_ends_with(parse_url($url, PHP_URL_PATH) ?: '', 'v1/locations/22') => Http::response(['name' => 'locations/22', 'title' => 'Atlas Çankaya']),
                default => Http::response([]),
            };
        });
    }
}
