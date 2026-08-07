<?php

namespace Tests\Feature;

use App\Models\CoreConnection;
use App\Models\CoreConnectionCredential;
use App\Models\DigitalAsset;
use App\Models\Evidence;
use App\Services\WordPressConnectionProbeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class WordPressConnectionProbeTest extends TestCase
{
    use RefreshDatabase;

    public function test_successful_public_wp_json_probe_creates_evidence_and_updates_connection(): void
    {
        Http::fake([
            'https://blog.example/wp-json*' => Http::response([
                'name' => 'Example Blog',
                'description' => 'Just another WordPress site',
                'url' => 'https://blog.example',
                'home' => 'https://blog.example',
                'namespaces' => ['oembed/1.0', 'wp/v2'],
            ], 200, [
                'Content-Type' => 'application/json',
                'X-WP-Version' => '6.5.3',
            ]),
        ]);

        $asset = DigitalAsset::factory()->create([
            'type' => 'website',
            'primary_url' => 'https://blog.example',
        ]);

        $connection = CoreConnection::factory()->create([
            'digital_asset_id' => $asset->id,
            'type' => WordPressConnectionProbeService::CONNECTION_TYPE,
            'name' => 'Primary WP',
            'config' => ['base_url' => 'https://blog.example'],
            'enabled' => true,
        ]);

        $run = app(WordPressConnectionProbeService::class)->probe($connection);

        $this->assertSame('completed', $run->status);
        $this->assertSame(WordPressConnectionProbeService::MODULE_ID, $run->module_id);
        $this->assertSame($connection->id, $run->core_connection_id);

        $evidence = Evidence::query()
            ->where('run_id', $run->id)
            ->where('type', WordPressConnectionProbeService::EVIDENCE_TYPE_WORDPRESS_SITE)
            ->first();

        $this->assertNotNull($evidence);
        $this->assertTrue($evidence->payload['ok']);
        $this->assertSame('Example Blog', $evidence->payload['site_name']);
        $this->assertTrue($evidence->payload['has_wp_v2']);
        $this->assertSame('6.5.3', $evidence->payload['wordpress_version']);
        $this->assertFalse($evidence->payload['auth_used']);
        $this->assertStringNotContainsString('password', json_encode($evidence->payload));

        $connection->refresh();
        $this->assertNotNull($connection->last_success_at);
        $this->assertNull($connection->last_error);

        Http::assertSent(function ($request): bool {
            return $request->method() === 'GET'
                && str_contains($request->url(), '/wp-json');
        });
    }

    public function test_application_password_auth_is_sent_but_not_persisted_in_evidence(): void
    {
        Http::fake([
            'https://private.example/wp-json*' => Http::response([
                'name' => 'Private WP',
                'url' => 'https://private.example',
                'home' => 'https://private.example',
                'namespaces' => ['wp/v2'],
            ], 200),
        ]);

        $asset = DigitalAsset::factory()->create([
            'type' => 'website',
            'primary_url' => 'https://private.example',
        ]);

        $connection = CoreConnection::factory()->create([
            'digital_asset_id' => $asset->id,
            'type' => 'wordpress',
            'config' => ['base_url' => 'https://private.example'],
            'enabled' => true,
        ]);

        CoreConnectionCredential::factory()->create([
            'connection_id' => $connection->id,
            'encrypted_payload' => [
                'username' => 'editor',
                'application_password' => 'abcd efgh ijkl mnop',
            ],
        ]);

        $run = app(WordPressConnectionProbeService::class)->probe($connection->fresh(['credential', 'digitalAsset']));

        $evidence = Evidence::query()->where('run_id', $run->id)->first();
        $this->assertTrue($evidence->payload['auth_used']);
        $encoded = json_encode($evidence->payload);
        $this->assertStringNotContainsString('abcd efgh ijkl mnop', (string) $encoded);
        $this->assertStringNotContainsString('editor', (string) $encoded);

        Http::assertSent(function ($request): bool {
            return $request->hasHeader('Authorization')
                && str_starts_with((string) $request->header('Authorization')[0], 'Basic ');
        });
    }

    public function test_connection_failure_records_last_error_without_throwing_for_completed_probe(): void
    {
        Http::fake(function () {
            throw new ConnectionException('Could not resolve host');
        });

        $asset = DigitalAsset::factory()->create([
            'type' => 'website',
            'primary_url' => 'https://down.example',
        ]);

        $connection = CoreConnection::factory()->create([
            'digital_asset_id' => $asset->id,
            'type' => 'wordpress',
            'config' => [],
            'enabled' => true,
        ]);

        $run = app(WordPressConnectionProbeService::class)->probe($connection);

        $this->assertSame('completed', $run->status);
        $evidence = Evidence::query()->where('run_id', $run->id)->first();
        $this->assertFalse($evidence->payload['ok']);
        $this->assertSame('connection', $evidence->payload['error_class']);

        $connection->refresh();
        $this->assertNull($connection->last_success_at);
        $this->assertStringContainsString('connection', (string) $connection->last_error);
    }

    public function test_rejects_non_wordpress_connection_type(): void
    {
        $asset = DigitalAsset::factory()->create(['type' => 'website']);
        $connection = CoreConnection::factory()->create([
            'digital_asset_id' => $asset->id,
            'type' => 'ga4',
            'enabled' => true,
        ]);

        $this->expectException(\InvalidArgumentException::class);

        app(WordPressConnectionProbeService::class)->probe($connection);
    }

    public function test_probe_is_get_only(): void
    {
        Http::fake([
            'https://ro.example/wp-json*' => Http::response([
                'name' => 'RO',
                'namespaces' => ['wp/v2'],
            ], 200),
        ]);

        $asset = DigitalAsset::factory()->create([
            'type' => 'website',
            'primary_url' => 'https://ro.example',
        ]);
        $connection = CoreConnection::factory()->create([
            'digital_asset_id' => $asset->id,
            'type' => 'wordpress',
            'config' => ['base_url' => 'https://ro.example'],
            'enabled' => true,
        ]);

        app(WordPressConnectionProbeService::class)->probe($connection);

        Http::assertSentCount(1);
        Http::assertSent(fn ($request): bool => $request->method() === 'GET');
        Http::assertNotSent(fn ($request): bool => in_array($request->method(), ['POST', 'PUT', 'PATCH', 'DELETE'], true));
    }
}
