<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\Yaml\Yaml;
use Tests\TestCase;

class NotificationsDataColumnMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_notifications_table_has_data_column_required_by_filament(): void
    {
        $this->assertTrue(Schema::hasTable('notifications'));
        $this->assertTrue(Schema::hasColumn('notifications', 'data'));
    }

    public function test_symfony_yaml_is_a_production_composer_dependency(): void
    {
        $composer = json_decode((string) File::get(base_path('composer.json')), true, 512, JSON_THROW_ON_ERROR);

        $this->assertIsArray($composer['require'] ?? null);
        $this->assertArrayHasKey('symfony/yaml', $composer['require']);
        $this->assertArrayNotHasKey('symfony/yaml', $composer['require-dev'] ?? []);
        $this->assertTrue(class_exists(Yaml::class));
    }
}
