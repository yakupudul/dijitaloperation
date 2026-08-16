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
        $this->assertContains('DATABASE', $names);
        $this->assertContains('QUEUE', $names);
        $this->assertContains('PRIVATE_STORAGE', $names);
        $this->assertContains('ROLES_SEED', $names);

        // In testing env, overall may WARN (APP_ENV / mail / redis) but must not mutate domain tables.
        $this->assertTrue(in_array($exit, [0, 1], true));
    }

    public function test_production_check_does_not_create_customers(): void
    {
        $this->seed(RoleAndPermissionSeeder::class);
        $before = Customer::query()->count();
        Artisan::call('moxdop:production-check');
        $this->assertSame($before, Customer::query()->count());
    }
}
