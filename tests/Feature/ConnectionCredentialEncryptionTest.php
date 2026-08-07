<?php

namespace Tests\Feature;

use App\Models\CoreConnection;
use App\Models\CoreConnectionCredential;
use App\Models\DigitalAsset;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ConnectionCredentialEncryptionTest extends TestCase
{
    use RefreshDatabase;

    public function test_connection_and_credential_can_be_created_with_encrypted_payload_roundtrip(): void
    {
        $asset = DigitalAsset::factory()->create();

        $connection = CoreConnection::factory()->create([
            'digital_asset_id' => $asset->id,
            'type' => 'ga4',
            'name' => 'GA4 Read-Only',
            'config' => ['property_id' => 'properties/123'],
            'enabled' => true,
        ]);

        $secretPayload = [
            'client_id' => 'sample-client-id',
            'client_secret' => 'sample-client-secret',
            'refresh_token' => 'sample-refresh-token',
        ];

        $credential = CoreConnectionCredential::factory()->create([
            'connection_id' => $connection->id,
            'encrypted_payload' => $secretPayload,
        ]);

        $this->assertTrue($connection->digitalAsset->is($asset));
        $this->assertTrue($connection->credential->is($credential));
        $this->assertTrue($credential->connection->is($connection));

        $storedPayload = DB::table('core_connection_credentials')
            ->where('id', $credential->id)
            ->value('encrypted_payload');

        $this->assertIsString($storedPayload);
        $this->assertNotSame(json_encode($secretPayload), $storedPayload);
        $this->assertStringNotContainsString('sample-client-secret', $storedPayload);
        $this->assertStringNotContainsString('sample-refresh-token', $storedPayload);

        $retrieved = CoreConnectionCredential::query()->findOrFail($credential->id);

        $this->assertSame($secretPayload, $retrieved->encrypted_payload);
        $this->assertArrayNotHasKey('encrypted_payload', $retrieved->toArray());
    }
}
