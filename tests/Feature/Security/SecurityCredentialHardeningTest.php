<?php

namespace Tests\Feature\Security;

use App\Enums\Security\SecretClass;
use App\Enums\Security\SecurityAuditEventKind;
use App\Models\Brand;
use App\Models\CoreConnection;
use App\Models\CoreConnectionCredential;
use App\Models\CoreIntegration;
use App\Models\CoreIntegrationCredential;
use App\Models\Customer;
use App\Models\DigitalAsset;
use App\Models\SecurityAuditEvent;
use App\Services\Integrations\Meta\MetaCredentialBroker;
use App\Services\Security\ConnectionCredentialAccessService;
use App\Services\Security\IntegrationCredentialAccessService;
use App\Services\Security\SecurityAuditRecorder;
use App\Support\Integrations\ProviderRegistry;
use App\Support\ReportDelivery\SecretHasher;
use App\Support\Security\EphemeralSecret;
use App\Support\Security\SecurityRedactor;
use App\Support\Security\TenantScopeGuard;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class SecurityCredentialHardeningTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleAndPermissionSeeder::class);
    }

    #[Test]
    public function recoverable_credentials_are_encrypted_at_rest_and_hidden_from_arrays(): void
    {
        $integration = CoreIntegration::factory()->create(['provider' => ProviderRegistry::META]);
        $secret = 'sample-meta-access-token-prompt64';
        $credential = CoreIntegrationCredential::factory()->create([
            'integration_id' => $integration->id,
            'credential_type' => CoreIntegrationCredential::TYPE_PROVIDER,
            'encrypted_payload' => ['access_token' => $secret],
        ]);

        $raw = DB::table('core_integration_credentials')->where('id', $credential->id)->value('encrypted_payload');
        $this->assertIsString($raw);
        $this->assertStringNotContainsString($secret, $raw);
        $this->assertArrayNotHasKey('encrypted_payload', $credential->fresh()->toArray());
        $this->assertSame($secret, $credential->fresh()->encrypted_payload['access_token']);
    }

    #[Test]
    public function ephemeral_secret_never_serializes_plaintext(): void
    {
        $secret = new EphemeralSecret('super-secret-value', 'test', 'meta', 1);
        $this->assertSame('super-secret-value', $secret->reveal());
        $this->assertArrayNotHasKey('value', $secret->toArray());
        $this->assertStringNotContainsString('super-secret-value', json_encode($secret->toArray()));
        $this->assertStringNotContainsString('super-secret-value', (string) $secret);
        $this->assertStringNotContainsString('super-secret-value', json_encode($secret->__debugInfo()));
    }

    #[Test]
    public function security_redactor_redacts_sensitive_fields_and_headers(): void
    {
        $redactor = app(SecurityRedactor::class);
        $out = $redactor->redactContext([
            'access_token' => 'tok_abc',
            'refresh_token' => 'ref_abc',
            'api_key' => 'key_abc',
            'password' => 'pw',
            'otp' => '123456',
            'integration_id' => 9,
            'token_count' => 3,
            'nested' => ['authorization' => 'Bearer x', 'ok' => true],
        ]);
        $this->assertSame(SecurityRedactor::REDACTED, $out['access_token']);
        $this->assertSame(SecurityRedactor::REDACTED, $out['refresh_token']);
        $this->assertSame(SecurityRedactor::REDACTED, $out['api_key']);
        $this->assertSame(9, $out['integration_id']);
        $this->assertSame(3, $out['token_count']);
        $this->assertSame(SecurityRedactor::REDACTED, $out['nested']['authorization']);

        $headers = $redactor->redactHeaders([
            'Authorization' => 'Bearer secret',
            'Cookie' => 'session=abc',
            'Accept' => 'application/json',
        ]);
        $this->assertSame(SecurityRedactor::REDACTED, $headers['Authorization']);
        $this->assertSame(SecurityRedactor::REDACTED, $headers['Cookie']);
        $this->assertSame('application/json', $headers['Accept']);
    }

    #[Test]
    public function redacted_logs_do_not_contain_known_dummy_secrets(): void
    {
        Log::spy();
        $dummy = 'sample-access-token-must-not-appear';
        $safe = app(SecurityRedactor::class)->redactContext([
            'access_token' => $dummy,
            'integration_id' => 1,
        ]);
        Log::info('security.test', $safe);
        Log::shouldHaveReceived('info')->withArgs(function (string $message, array $context) use ($dummy): bool {
            return $message === 'security.test'
                && ($context['access_token'] ?? null) === SecurityRedactor::REDACTED
                && ! str_contains(json_encode($context), $dummy);
        });
    }

    #[Test]
    public function meta_credential_broker_returns_ephemeral_secret_only(): void
    {
        $integration = CoreIntegration::factory()->create(['provider' => ProviderRegistry::META]);
        CoreIntegrationCredential::factory()->create([
            'integration_id' => $integration->id,
            'credential_type' => CoreIntegrationCredential::TYPE_PROVIDER,
            'encrypted_payload' => ['access_token' => 'EAAG-sample-token-p64'],
        ]);
        $integration->load('providerCredential');

        $secret = app(MetaCredentialBroker::class)->accessTokenFor($integration->fresh());
        $this->assertInstanceOf(EphemeralSecret::class, $secret);
        $this->assertSame('EAAG-sample-token-p64', $secret->reveal());
        $this->assertStringNotContainsString('EAAG-sample-token-p64', json_encode($secret->toArray()));
    }

    #[Test]
    public function connection_credential_access_hides_wordpress_password_from_status(): void
    {
        $asset = DigitalAsset::factory()->create();
        $connection = CoreConnection::factory()->create([
            'digital_asset_id' => $asset->id,
            'type' => 'wordpress',
        ]);
        CoreConnectionCredential::factory()->create([
            'connection_id' => $connection->id,
            'encrypted_payload' => [
                'username' => 'editor',
                'application_password' => 'wp-app-password-sample',
            ],
        ]);
        $connection->load('credential');

        $access = app(ConnectionCredentialAccessService::class);
        $status = $access->status($connection);
        $this->assertTrue($status['has_application_password']);
        $this->assertStringNotContainsString('wp-app-password-sample', json_encode($status));
        $this->assertSame('wp-app-password-sample', $access->wordpressApplicationPassword($connection)?->reveal());
    }

    #[Test]
    public function tenant_scope_guard_rejects_forged_customer_brand_and_asset(): void
    {
        $customerA = Customer::factory()->create();
        $customerB = Customer::factory()->create();
        $brandB = Brand::factory()->create(['customer_id' => $customerB->id]);
        $assetB = DigitalAsset::factory()->create(['brand_id' => $brandB->id]);
        $guard = app(TenantScopeGuard::class);

        $this->expectException(ValidationException::class);
        $guard->resolveConsistentScope([
            'customer_id' => $customerA->id,
            'brand_id' => $brandB->id,
            'digital_asset_id' => $assetB->id,
        ]);
    }

    #[Test]
    public function tenant_scope_guard_accepts_consistent_scope(): void
    {
        $customer = Customer::factory()->create();
        $brand = Brand::factory()->create(['customer_id' => $customer->id]);
        $asset = DigitalAsset::factory()->create(['brand_id' => $brand->id]);
        $resolved = app(TenantScopeGuard::class)->resolveConsistentScope([
            'customer_id' => $customer->id,
            'brand_id' => $brand->id,
            'digital_asset_id' => $asset->id,
        ]);
        $this->assertSame((int) $customer->id, (int) $resolved['customer']->id);
        $this->assertSame((int) $brand->id, (int) $resolved['brand']->id);
        $this->assertSame((int) $asset->id, (int) $resolved['digital_asset']->id);
    }

    #[Test]
    public function share_locator_and_otp_are_hashed_not_reversibly_encrypted(): void
    {
        $locator = SecretHasher::randomToken();
        $otp = SecretHasher::otpCode();
        $locatorHash = SecretHasher::hash($locator);
        $otpHash = SecretHasher::hash($otp);
        $this->assertNotSame($locator, $locatorHash);
        $this->assertNotSame($otp, $otpHash);
        $this->assertTrue(SecretHasher::equals($locator, $locatorHash));
        $this->assertTrue(SecretHasher::equals($otp, $otpHash));
        $this->assertFalse(str_starts_with($locatorHash, 'eyJ')); // not Laravel ciphertext
    }

    #[Test]
    public function security_audit_never_stores_secret_values(): void
    {
        app(SecurityAuditRecorder::class)->record(
            SecurityAuditEventKind::CredentialRotated,
            reason: 'TEST',
            metadata: [
                'access_token' => 'must-not-persist',
                'integration_id' => 42,
            ],
        );
        $event = SecurityAuditEvent::query()->first();
        $this->assertNotNull($event);
        $this->assertSame(SecurityAuditEventKind::CredentialRotated->value, $event->kind);
        $this->assertSame(SecurityRedactor::REDACTED, $event->metadata['access_token'] ?? null);
        $this->assertSame(42, $event->metadata['integration_id'] ?? null);
        $this->assertStringNotContainsString('must-not-persist', json_encode($event->toArray()));
    }

    #[Test]
    public function agent_credential_access_is_forbidden(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        app(IntegrationCredentialAccessService::class)->denyAgentAccess('AgentRun');
    }

    #[Test]
    public function secret_class_taxonomy_is_documented(): void
    {
        $this->assertSame('RECOVERABLE_CREDENTIAL', SecretClass::RecoverableCredential->value);
        $this->assertSame('NON_RECOVERABLE_AUTH_SECRET', SecretClass::NonRecoverableAuthSecret->value);
        $this->assertSame('DEPLOYMENT_SECRET', SecretClass::DeploymentSecret->value);
        $this->assertSame('NON_SECRET_SECURITY_METADATA', SecretClass::NonSecretSecurityMetadata->value);
        $this->assertFalse(class_exists('App\\Models\\CredentialV2'));
        $this->assertFalse(class_exists('App\\Models\\RBACV2'));
        $this->assertFalse(class_exists('App\\Models\\TenantV2'));
    }

    #[Test]
    public function reencrypt_command_dry_run_does_not_print_secrets(): void
    {
        $integration = CoreIntegration::factory()->create(['provider' => ProviderRegistry::META]);
        CoreIntegrationCredential::factory()->create([
            'integration_id' => $integration->id,
            'credential_type' => CoreIntegrationCredential::TYPE_PROVIDER,
            'encrypted_payload' => ['access_token' => 'sample-reencrypt-token'],
        ]);

        $exit = Artisan::call('moxdop:security:reencrypt-credentials', ['--dry-run' => true, '--batch' => 10]);
        $output = Artisan::output();
        $this->assertSame(0, $exit);
        $this->assertStringNotContainsString('sample-reencrypt-token', $output);
        $this->assertStringNotContainsString('base64:', $output);
    }

    #[Test]
    public function no_credential_v2_or_custom_crypto_primitives(): void
    {
        $this->assertFalse(class_exists('App\\Services\\Security\\CredentialV2'));
        $this->assertFalse(class_exists('App\\Services\\Security\\ProviderCredentialV2'));
        $this->assertFalse(class_exists('App\\Services\\Security\\IntegrationSecretV2'));
        $this->assertTrue(class_exists(IntegrationCredentialAccessService::class));
        $this->assertTrue(class_exists(SecurityRedactor::class));
        $this->assertTrue(class_exists(TenantScopeGuard::class));
    }
}
