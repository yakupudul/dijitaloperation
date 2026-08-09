<?php

namespace Database\Factories;

use App\Models\Brand;
use App\Models\BrandIntelligenceContext;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<BrandIntelligenceContext>
 */
class BrandIntelligenceContextFactory extends Factory
{
    protected $model = BrandIntelligenceContext::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'brand_id' => Brand::factory(),
            'business_summary' => 'Aesthetic surgery clinic serving international patients.',
            'business_model' => 'healthcare_clinic',
            'products_services' => [
                ['name' => 'Mommy Makeover', 'description' => null],
                ['name' => 'Post-bariatric surgery', 'description' => null],
                ['name' => 'Breast aesthetic', 'description' => null],
                ['name' => 'Rhinoplasty', 'description' => null],
            ],
            'priority_offerings' => [
                'Post-bariatric surgery',
                'Mommy Makeover',
                'Breast aesthetic',
            ],
            'target_audiences' => [
                ['name' => 'International medical travelers', 'note' => null],
                ['name' => 'Post-weight-loss patients', 'note' => null],
            ],
            'target_markets' => [
                ['name' => 'Germany', 'note' => null],
                ['name' => 'United Kingdom', 'note' => null],
                ['name' => 'Netherlands', 'note' => null],
            ],
            'business_goals' => [
                ['goal' => 'Increase qualified consultation requests', 'note' => null],
            ],
            'conversion_goals' => [
                ['type' => 'form_submission', 'label' => 'Consultation form', 'note' => null],
                ['type' => 'whatsapp_conversation', 'label' => null, 'note' => 'Primary channel'],
            ],
            'positioning' => 'Specialist aesthetic surgery with structured post-bariatric care.',
            'differentiators' => [
                'Post-bariatric focus',
                'Multilingual patient coordination',
            ],
            'known_competitors' => [
                ['name' => 'Example Clinic', 'url' => 'https://example-clinic.com', 'note' => null],
            ],
            'important_constraints' => 'Regulated healthcare advertising; no patient before/after content in some markets.',
            'source' => BrandIntelligenceContext::SOURCE_OPERATOR,
            'updated_by' => null,
        ];
    }
}
