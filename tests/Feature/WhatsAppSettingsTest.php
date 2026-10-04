<?php

namespace Tests\Feature;

use App\Jobs\WhatsApp\CheckWhatsAppConnection;
use App\Models\User;
use App\Models\WhatsAppConversation;
use App\Models\WhatsAppWebhookReceipt;
use App\Services\WhatsApp\WhatsAppConnection;
use App\Support\Roles;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class WhatsAppSettingsTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private WhatsAppConnection $connection;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleAndPermissionSeeder::class);
        $this->admin = User::factory()->create(['is_active' => true]);
        $this->admin->assignRole(Roles::ADMIN);
        $this->connection = app(WhatsAppConnection::class);
        Http::fake();
        $this->connection->save($this->admin, $this->input());
    }

    private function input(array $overrides = []): array
    {
        return array_replace([
            'waba_id' => '111111111', 'phone_number_id' => '222222222',
            'business_phone' => '905551112233', 'business_context' => 'Test service terms',
            'access_token' => 'test-access-token', 'app_secret' => 'test-app-secret',
            'verify_token' => 'test-verify-token-12345',
            'enabled' => true, 'automatic_suggestions' => false,
        ], $overrides);
    }

    public function test_initial_binding_can_be_corrected_after_an_ignored_completed_receipt(): void
    {
        $integration = $this->connection->integration();
        WhatsAppWebhookReceipt::query()->create([
            'integration_id' => $integration->id, 'payload_hash' => str_repeat('a', 64),
            'status' => 'completed', 'accepted_count' => 0, 'ignored_count' => 1,
        ]);
        $this->connection->save($this->admin, $this->input([
            'waba_id' => '333333333', 'phone_number_id' => '444444444',
            'access_token' => '', 'app_secret' => '', 'verify_token' => '',
        ]));
        $fresh = $this->connection->integration();
        $this->assertSame($integration->id, $fresh->id);
        $this->assertSame('444444444', $fresh->config['phone_number_id']);
        $this->assertSame('test-access-token', $this->connection->secrets($fresh)['access_token']);
        $this->assertDatabaseCount('whatsapp_webhook_receipts', 1);
        Http::assertNothingSent();
    }

    public function test_pending_and_failed_receipts_block_binding_change_without_mutating_credentials(): void
    {
        $integration = $this->connection->integration();
        $receipt = WhatsAppWebhookReceipt::query()->create([
            'integration_id' => $integration->id, 'payload_hash' => str_repeat('b', 64),
            'status' => 'pending', 'payload' => ['entry' => []],
        ]);
        foreach (['pending', 'failed'] as $status) {
            $receipt->update(['status' => $status]);
            try {
                $this->connection->save($this->admin, $this->input([
                    'phone_number_id' => '444444444', 'access_token' => 'replacement-token',
                ]));
                $this->fail('Unprocessed receipts must retain their original binding.');
            } catch (ValidationException $exception) {
                $this->assertArrayHasKey('phone_number_id', $exception->errors());
            }
            $fresh = $this->connection->integration();
            $this->assertSame('222222222', $fresh->config['phone_number_id']);
            $this->assertSame('test-access-token', $this->connection->secrets($fresh)['access_token']);
        }
    }

    public function test_history_blocks_rebinding_but_allows_ordinary_settings_save(): void
    {
        $integration = $this->connection->integration();
        WhatsAppConversation::query()->create([
            'integration_id' => $integration->id, 'phone_number_id' => '222222222',
            'contact_id' => '905554445566',
        ]);
        $this->connection->save($this->admin, $this->input(['business_context' => 'Updated terms']));
        $this->assertSame('Updated terms', $this->connection->integration()->config['business_context']);
        $this->expectException(ValidationException::class);
        $this->connection->save($this->admin, $this->input(['waba_id' => '333333333']));
    }

    public function test_a_check_started_before_settings_changed_cannot_overwrite_the_new_state(): void
    {
        $integration = $this->connection->integration();
        $requestId = $integration->config['connection_check_request_id'];
        Http::fake(function () {
            $this->connection->save($this->admin, $this->input(['business_phone' => '905559998877']));

            return Http::response(['id' => '222222222', 'display_phone_number' => '+90 555 111 22 33']);
        });
        (new CheckWhatsAppConnection($integration->id, $requestId))->handle($this->connection);
        $this->assertSame('not_checked', $this->connection->integration()->config['connection_check']);
        $this->assertNull($this->connection->integration()->config['connection_checked_at']);
    }
}
