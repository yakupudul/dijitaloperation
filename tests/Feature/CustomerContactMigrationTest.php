<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class CustomerContactMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_customer_contacts_table_has_expected_columns_and_foreign_key(): void
    {
        $this->assertTrue(Schema::hasTable('customer_contacts'));

        $this->assertTrue(Schema::hasColumns('customer_contacts', [
            'id',
            'customer_id',
            'name',
            'email',
            'phone',
            'title',
            'created_at',
            'updated_at',
        ]));

        $foreignKeys = Schema::getForeignKeys('customer_contacts');

        $this->assertNotEmpty($foreignKeys);

        $customerForeignKey = collect($foreignKeys)->first(
            fn (array $foreignKey): bool => $foreignKey['columns'] === ['customer_id']
                && $foreignKey['foreign_table'] === 'customers'
                && $foreignKey['foreign_columns'] === ['id']
        );

        $this->assertNotNull($customerForeignKey);
        $this->assertSame('cascade', $customerForeignKey['on_delete']);
    }
}
