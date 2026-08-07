<?php

namespace Tests\Feature;

use App\Enums\CustomerStatus;
use App\Enums\CustomerType;
use App\Models\Customer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CustomerTest extends TestCase
{
    use RefreshDatabase;

    public function test_customer_can_be_created_via_factory_and_persisted(): void
    {
        $customer = Customer::factory()->create([
            'name' => 'Acme Agency Client',
            'type' => CustomerType::Company,
            'legal_name' => 'Acme Ltd.',
            'status' => CustomerStatus::Active,
            'primary_email' => 'ops@acme.example',
            'primary_phone' => '+15551234567',
            'service_started_at' => '2026-01-15',
            'services_received' => "SEO\nWebsite maintenance",
        ]);

        $this->assertDatabaseHas('customers', [
            'id' => $customer->id,
            'name' => 'Acme Agency Client',
            'type' => CustomerType::Company->value,
            'legal_name' => 'Acme Ltd.',
            'status' => CustomerStatus::Active->value,
            'primary_email' => 'ops@acme.example',
            'primary_phone' => '+15551234567',
            'services_received' => "SEO\nWebsite maintenance",
        ]);

        $this->assertTrue($customer->service_started_at->equalTo('2026-01-15'));
        $this->assertSame(CustomerType::Company, $customer->type);
        $this->assertSame(CustomerStatus::Active, $customer->status);
    }

    public function test_optional_legal_name_may_be_null(): void
    {
        $customer = Customer::factory()->create([
            'legal_name' => null,
        ]);

        $this->assertDatabaseHas('customers', [
            'id' => $customer->id,
            'legal_name' => null,
        ]);

        $this->assertNull($customer->fresh()->legal_name);
    }
}
