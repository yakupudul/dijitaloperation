<?php

namespace Tests\Feature\Performance;

use App\Filament\App\Resources\Findings\FindingResource;
use App\Filament\App\Resources\Recommendations\RecommendationResource;
use App\Filament\App\Resources\Tasks\TaskResource;
use App\Models\Brand;
use App\Models\Customer;
use App\Models\DigitalAsset;
use App\Models\Finding;
use App\Models\Recommendation;
use App\Models\Task;
use App\Models\User;
use App\Support\Performance\QueryCountProbe;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\Group;
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

    public function test_finding_and_task_filament_queries_eager_load_relationships(): void
    {
        $customer = Customer::factory()->create();
        $brand = Brand::factory()->create(['customer_id' => $customer->id]);
        $asset = DigitalAsset::factory()->create(['brand_id' => $brand->id]);
        $assignee = User::factory()->create();

        Finding::factory()->count(15)->create([
            'customer_id' => $customer->id,
            'brand_id' => $brand->id,
            'digital_asset_id' => $asset->id,
        ]);
        Task::factory()->count(15)->create([
            'brand_id' => $brand->id,
            'digital_asset_id' => $asset->id,
            'assignee_id' => $assignee->id,
            'recommendation_id' => null,
        ]);
        Recommendation::factory()->count(15)->create([
            'digital_asset_id' => $asset->id,
            'finding_id' => Finding::factory()->create([
                'digital_asset_id' => $asset->id,
                'customer_id' => $customer->id,
                'brand_id' => $brand->id,
            ])->id,
        ]);

        $probe = app(QueryCountProbe::class);

        $findings = $probe->measure(function () {
            $rows = FindingResource::getEloquentQuery()->limit(15)->get();
            foreach ($rows as $row) {
                $row->digitalAsset?->name;
            }

            return $rows;
        });

        $tasks = $probe->measure(function () {
            $rows = TaskResource::getEloquentQuery()->limit(15)->get();
            foreach ($rows as $row) {
                $row->brand?->name;
                $row->digitalAsset?->name;
                $row->assignee?->name;
            }

            return $rows;
        });

        $recs = $probe->measure(function () {
            $rows = RecommendationResource::getEloquentQuery()->limit(15)->get();
            foreach ($rows as $row) {
                $row->digitalAsset?->name;
            }

            return $rows;
        });

        // Base query + eager loads — must not be 1 + N relationship queries.
        $this->assertLessThanOrEqual(3, $findings['queries']);
        $this->assertLessThanOrEqual(4, $tasks['queries']);
        $this->assertLessThanOrEqual(3, $recs['queries']);
    }

    public function test_work_list_query_count_stable_from_10_to_100_tasks(): void
    {
        $customer = Customer::factory()->create();
        $brand = Brand::factory()->create(['customer_id' => $customer->id]);
        $asset = DigitalAsset::factory()->create(['brand_id' => $brand->id]);
        $assignee = User::factory()->create();

        Task::factory()->count(10)->create([
            'brand_id' => $brand->id,
            'digital_asset_id' => $asset->id,
            'assignee_id' => $assignee->id,
            'recommendation_id' => null,
        ]);

        $probe = app(QueryCountProbe::class);
        $ten = $probe->measure(function () {
            $rows = TaskResource::getEloquentQuery()->limit(100)->get();
            foreach ($rows as $row) {
                $row->assignee?->name;
            }

            return $rows;
        });

        Task::factory()->count(90)->create([
            'brand_id' => $brand->id,
            'digital_asset_id' => $asset->id,
            'assignee_id' => $assignee->id,
            'recommendation_id' => null,
        ]);

        $hundred = $probe->measure(function () {
            $rows = TaskResource::getEloquentQuery()->limit(100)->get();
            foreach ($rows as $row) {
                $row->assignee?->name;
            }

            return $rows;
        });

        $this->assertSame($ten['queries'], $hundred['queries']);
    }
}
