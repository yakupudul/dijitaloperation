<?php

namespace Tests\Feature\Ga4;

use App\Enums\Collection\CollectionRunStatus;
use App\Models\Collection\CollectionResourceRun;
use App\Models\Collection\CollectionRun;
use App\Models\CoreExternalResource;
use App\Models\CoreIntegration;
use App\Models\CoreIntegrationCredential;
use App\Support\Integrations\Google\GoogleResourceType;
use App\Support\Integrations\Google\GoogleScopes;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class Ga4CentralRestatementCommandTest extends TestCase
{
    use RefreshDatabase;

    #[Test]
    public function one_property_with_an_active_collection_does_not_block_its_siblings(): void
    {
        Queue::fake();
        Cache::flush();
        config([
            'moxdop.google.client_id' => 'cid',
            'moxdop.google.client_secret' => 'csecret',
            'moxdop-collection.queue_connection' => 'database',
            'moxdop-collection.require_queue_connection' => false,
        ]);
        Http::fake(function ($request) {
            $url = $request->url();
            if (str_contains($url, 'dataStreams')) {
                return Http::response(['dataStreams' => []], 200);
            }
            if (str_contains($url, 'analyticsadmin')) {
                return Http::response(['name' => 'properties/1', 'timeZone' => 'UTC', 'currencyCode' => 'USD'], 200);
            }

            return Http::response(['error' => ['message' => 'unexpected '.$url]], 500);
        });

        $integration = CoreIntegration::factory()->google()->create([
            'status' => CoreIntegration::STATUS_ACTIVE,
            'config' => ['granted_scopes' => [GoogleScopes::ANALYTICS_READONLY]],
        ]);
        CoreIntegrationCredential::factory()->provider()->create([
            'integration_id' => $integration->id,
            'encrypted_payload' => ['client_id' => 'cid', 'client_secret' => 'csecret'],
        ]);
        CoreIntegrationCredential::factory()->authorization()->create([
            'integration_id' => $integration->id,
            'encrypted_payload' => [
                'access_token' => 'ga4-access-token',
                'refresh_token' => 'ga4-refresh-token',
                'scope' => GoogleScopes::ANALYTICS_READONLY,
            ],
            'expires_at' => now()->addHour(),
        ]);

        $busy = $this->property($integration, 'properties/1');
        $idle = $this->property($integration, 'properties/2');
        $this->centralRun($busy, CollectionRunStatus::Completed);
        $this->centralRun($busy, CollectionRunStatus::Running);
        $this->centralRun($idle, CollectionRunStatus::Completed);

        $this->artisan('moxdop:ga4:central-restatement')
            ->expectsOutputToContain('queued for 1 property')
            ->assertSuccessful();

        $this->assertSame(2, CollectionResourceRun::query()->where('external_resource_id', $idle->id)->count());
        $this->assertSame(2, CollectionResourceRun::query()->where('external_resource_id', $busy->id)->count());
    }

    private function property(CoreIntegration $integration, string $externalId): CoreExternalResource
    {
        return CoreExternalResource::factory()->create([
            'integration_id' => $integration->id,
            'provider' => 'google',
            'resource_type' => GoogleResourceType::GA4_PROPERTY,
            'external_id' => $externalId,
            'display_name' => 'GA4 '.$externalId,
            'status' => CoreExternalResource::STATUS_AVAILABLE,
        ]);
    }

    private function centralRun(CoreExternalResource $resource, CollectionRunStatus $status): void
    {
        $run = CollectionRun::factory()->create(['digital_asset_id' => null, 'status' => $status]);
        CollectionResourceRun::factory()->create([
            'collection_run_id' => $run->id,
            'provider_or_source' => 'GA4',
            'external_resource_id' => $resource->id,
            'digital_asset_id' => null,
            'core_asset_binding_id' => null,
            'status' => $status,
            'metadata' => ['collection_scope' => 'provider_resource_first'],
        ]);
    }
}
