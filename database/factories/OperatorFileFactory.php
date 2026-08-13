<?php

namespace Database\Factories;

use App\Models\OperatorFile;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<OperatorFile>
 */
class OperatorFileFactory extends Factory
{
    protected $model = OperatorFile::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = fake()->words(2, true).'.pdf';

        return [
            'uuid' => (string) Str::uuid(),
            'user_id' => User::factory(),
            'disk' => 'local',
            'path' => 'operator-files/'.Str::uuid().'/'.$name,
            'original_name' => $name,
            'mime' => 'application/pdf',
            'size' => fake()->numberBetween(1_024, 2_000_000),
            'scope_type' => 'personal',
            'scope_id' => null,
            'customer_id' => null,
            'brand_id' => null,
            'digital_asset_id' => null,
            'task_id' => null,
            'description' => fake()->optional()->sentence(),
            'tags' => [],
        ];
    }
}
