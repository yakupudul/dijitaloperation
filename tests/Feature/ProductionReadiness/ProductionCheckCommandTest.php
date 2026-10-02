<?php

namespace Tests\Feature\ProductionReadiness;

use App\Models\Customer;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

class ProductionCheckCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_production_check_is_read_only_and_emits_results(): void
    {
        $this->seed(RoleAndPermissionSeeder::class);

        $exit = Artisan::call('moxdop:production-check', ['--json' => true]);
        $output = Artisan::output();

        $this->assertNotSame('', $output);
        $payload = json_decode($output, true);
        $this->assertIsArray($payload);
        $this->assertArrayHasKey('overall', $payload);
        $this->assertArrayHasKey('checks', $payload);
        $this->assertContains($payload['overall'], ['PASS', 'WARN', 'FAIL']);

        $names = collect($payload['checks'])->pluck('check')->all();
        $this->assertContains('APP_KEY', $names);
        $this->assertContains('HTTPS', $names);
        $this->assertContains('OAUTH_CALLBACKS', $names);
        $this->assertContains('DATABASE', $names);
        $this->assertContains('QUEUE', $names);
        $this->assertContains('PRIVATE_STORAGE', $names);
        $this->assertContains('ROLES_SEED', $names);

        $this->assertStringNotContainsString((string) config('app.key'), $output);
        $this->assertStringNotContainsString('base64:', $output);

        // In testing env, overall may WARN (APP_ENV / mail / redis) but must not mutate domain tables.
        $this->assertTrue(in_array($exit, [0, 1], true));
    }

    public function test_production_check_fails_https_when_staging_uses_http(): void
    {
        $this->seed(RoleAndPermissionSeeder::class);

        config([
            'app.env' => 'staging',
            'app.debug' => false,
            'app.url' => 'http://127.0.0.1:8000',
            'app.force_https' => false,
            'session.secure' => false,
        ]);

        Artisan::call('moxdop:production-check', ['--json' => true]);
        $output = Artisan::output();
        $payload = json_decode($output, true);
        $this->assertIsArray($payload);

        $https = collect($payload['checks'])->firstWhere('check', 'HTTPS');
        $this->assertIsArray($https);
        $this->assertSame('FAIL', $https['result']);
        $this->assertStringNotContainsString('127.0.0.1', $output);
        $this->assertSame('FAIL', $payload['overall']);
    }

    public function test_production_check_passes_https_when_staging_contract_is_set(): void
    {
        $this->seed(RoleAndPermissionSeeder::class);

        config([
            'app.env' => 'staging',
            'app.debug' => false,
            'app.url' => 'https://app.moximu.com',
            'app.force_https' => true,
            'session.secure' => true,
        ]);

        Artisan::call('moxdop:production-check', ['--json' => true]);
        $payload = json_decode(Artisan::output(), true);
        $this->assertIsArray($payload);

        $https = collect($payload['checks'])->firstWhere('check', 'HTTPS');
        $this->assertIsArray($https);
        $this->assertSame('PASS', $https['result']);
        $this->assertStringNotContainsString('app.moximu.com', (string) $https['detail']);
    }

    public function test_production_check_does_not_create_customers(): void
    {
        $this->seed(RoleAndPermissionSeeder::class);
        $before = Customer::query()->count();
        Artisan::call('moxdop:production-check');
        $this->assertSame($before, Customer::query()->count());
    }
}
