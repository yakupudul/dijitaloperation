<?php

namespace Database\Factories;

use App\Enums\PlaybookStatus;
use App\Models\Playbook;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Playbook>
 */
class PlaybookFactory extends Factory
{
    protected $model = Playbook::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'stable_key' => null,
            'status' => PlaybookStatus::Active->value,
            'current_revision_id' => null,
            'created_by' => User::factory(),
        ];
    }
}
