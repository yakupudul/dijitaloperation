<?php

namespace Tests\Feature\Performance;

use App\Models\Brand;
use App\Models\Customer;
use App\Support\Performance\QueryCountProbe;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

#[Group('performance')]
class QueryCountRegressionTest extends TestCase
{
    use RefreshDatabase;

    public function test_customer_list_query_count_does_not_scale_linearly_with_rows(): void
    {
        Customer::factory()->count(10)->create();
        $probe = app(QueryCountProbe::class);

        $ten = $probe->measure(fn () => Customer::query()->orderBy('id')->get(['id', 'name']));
        Customer::factory()->count(90)->create();
        $hundred = $probe->measure(fn () => Customer::query()->orderBy('id')->get(['id', 'name']));

        $this->assertSame(10, $ten['result']->count());
        $this->assertSame(100, $hundred['result']->count());
        $this->assertSame($ten['queries'], $hundred['queries']);
        $this->assertLessThanOrEqual(2, $hundred['queries']);
    }

    public function test_brand_list_with_count_does_not_n_plus_one(): void
    {
        $customers = Customer::factory()->count(10)->create();
        foreach ($customers as $customer) {
            Brand::factory()->count(2)->create(['customer_id' => $customer->id]);
        }

        $probe = app(QueryCountProbe::class);
        $tenCustomers = $probe->measure(function () {
            return Brand::query()->with(['customer'])->withCount('digitalAssets')->orderBy('id')->get();
        });

        $more = Customer::factory()->count(40)->create();
        foreach ($more as $customer) {
            Brand::factory()->create(['customer_id' => $customer->id]);
        }

        $fiftyCustomersBrands = $probe->measure(function () {
            return Brand::query()->with(['customer'])->withCount('digitalAssets')->orderBy('id')->get();
        });

        $this->assertSame($tenCustomers['queries'], $fiftyCustomersBrands['queries']);
        $this->assertLessThanOrEqual(3, $fiftyCustomersBrands['queries']);
    }
}
