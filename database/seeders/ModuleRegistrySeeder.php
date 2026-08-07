<?php

namespace Database\Seeders;

use App\Models\ModuleRegistry;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\File;

class ModuleRegistrySeeder extends Seeder
{
    /**
     * Seed registry rows from modules under config('app-modules.modules_directory').
     */
    public function run(): void
    {
        $directory = base_path((string) config('app-modules.modules_directory', 'app-modules'));

        if (! File::isDirectory($directory)) {
            return;
        }

        foreach (File::directories($directory) as $modulePath) {
            $moduleId = basename($modulePath);
            $composerPath = $modulePath.'/composer.json';

            if (! File::exists($composerPath)) {
                continue;
            }

            /** @var array{version?: string}|null $composer */
            $composer = json_decode(File::get($composerPath), true);
            $installedVersion = is_array($composer) ? ($composer['version'] ?? null) : null;

            ModuleRegistry::query()->updateOrCreate(
                ['module_id' => $moduleId],
                [
                    'enabled' => true,
                    'installed_version' => is_string($installedVersion) ? $installedVersion : null,
                ],
            );
        }
    }
}
