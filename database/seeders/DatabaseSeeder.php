<?php

namespace Database\Seeders;

use App\Services\Playbooks\SeedDefaultPlaybooks;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $this->call([
            RoleAndPermissionSeeder::class,
            ModuleRegistrySeeder::class,
        ]);

        // Idempotent curated Playbooks (Prompt 45). Never overwrites operator edits.
        app(SeedDefaultPlaybooks::class)->seed();
    }
}
