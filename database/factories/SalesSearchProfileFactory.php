<?php

namespace Database\Factories;

use App\Models\SalesSearchProfile;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SalesSearchProfile>
 */
class SalesSearchProfileFactory extends Factory
{
    protected $model = SalesSearchProfile::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => 'Website Intent '.$this->faker->unique()->numerify('####'),
            'service_definition_code' => 'website_design',
            'language' => 'tr',
            'country' => 'TR',
            'location' => null,
            'include_concepts' => [
                'web sitesi yaptırmak',
                'web tasarım ajansı arıyoruz',
            ],
            'exclude_concepts' => [
                'nasıl yapılır',
                'ücretsiz',
            ],
            'minimum_intent_confidence' => 60,
            'active' => true,
            'owner_user_id' => User::factory(),
        ];
    }
}
