<?php

namespace Tests\Feature;

use App\Livewire\Operator\Portfolio\BrandShow;
use App\Models\Brand;
use App\Models\Customer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class BrandOperatorCanonicalContextTest extends TestCase
{
    use RefreshDatabase;

    public function test_operator_business_context_edits_persist_to_canonical_brand_context(): void
    {
        $user = User::factory()->create(['name' => 'Yakup']);
        $this->actingAs($user);

        $customer = Customer::factory()->create();
        $brand = Brand::factory()->create(['customer_id' => $customer->id]);

        Livewire::test(BrandShow::class, ['brand' => (string) $brand->id])
            ->set('context_business_summary', 'Moximu dijital operasyon ajansı.')
            ->set('context_business_model', 'B2B service')
            ->set('context_priority_offerings', 'Google Ads, SEO')
            ->set('context_target_audiences', 'KOBİ, Sağlık markaları')
            ->set('context_positioning', 'Veri ve operasyon odaklı büyüme ortağı')
            ->set('context_differentiators', 'Tek merkezden operasyon, Gerçek veri')
            ->set('context_business_goals', 'Nitelikli müşteri kazanımı')
            ->set('context_conversion_goals', 'Lead form, WhatsApp')
            ->set('context_constraints', 'Bütçe kontrollü büyüme')
            ->call('saveBusinessContext')
            ->assertHasNoErrors();

        $context = $brand->fresh()->intelligenceContext;

        $this->assertNotNull($context);
        $this->assertSame('Moximu dijital operasyon ajansı.', $context->business_summary);
        $this->assertSame('B2B service', $context->business_model);
        $this->assertSame('Veri ve operasyon odaklı büyüme ortağı', $context->positioning);
        $this->assertSame('operator', $context->source);
        $this->assertSame($user->id, $context->updated_by);
        $this->assertNotEmpty($context->business_goals);
        $this->assertNotEmpty($context->conversion_goals);
        $this->assertNotEmpty($context->priority_offerings);
    }
}
