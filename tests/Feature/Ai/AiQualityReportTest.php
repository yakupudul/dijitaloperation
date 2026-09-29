<?php

namespace Tests\Feature\Ai;

use App\Models\AiProduction;
use App\Models\Brand;
use App\Models\Customer;
use App\Models\DigitalAsset;
use App\Models\User;
use App\Support\Roles;
use Carbon\Carbon;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class AiQualityReportTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Brand $brand;

    private DigitalAsset $ads;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(Carbon::parse('2026-10-05 10:00:00'));
        $this->seed(RoleAndPermissionSeeder::class);
        $this->admin = User::factory()->create(['is_active' => true]);
        $this->admin->assignRole(Roles::ADMIN);
        $this->actingAs($this->admin);
        $this->brand = Brand::factory()->create(['customer_id' => Customer::factory()->create()->id]);
        $this->ads = DigitalAsset::factory()->create(['brand_id' => $this->brand->id, 'type' => 'google_ads']);
    }

    public function test_page_is_admin_only(): void
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole(Roles::TEAM_MEMBER);
        $this->actingAs($user)->get(route('operator.settings.ai-quality'))->assertForbidden();
    }

    /** @param array<string, mixed> $content */
    private function production(string $kind, string $subjectType, int $subjectId, int $version, ?string $prompt, string $status = 'new', ?int $rating = null, array $content = ['x' => 1], ?Carbon $createdAt = null): void
    {
        $row = AiProduction::query()->create([
            'kind' => $kind, 'subject_type' => $subjectType, 'subject_id' => $subjectId, 'brand_id' => $this->brand->id, 'version' => $version, 'content' => $content,
            'content_hash' => hash('sha256', uniqid('', true)), 'prompt_version' => $prompt, 'status' => $status, 'rating' => $rating,
        ]);
        $row->forceFill(['created_at' => $createdAt ?? now()->subDays(3)])->save();
    }

    private function review(?string $reply): int
    {
        return DB::table('gbp_reviews')->insertGetId([
            'digital_asset_id' => 1, 'external_resource_id' => 1, 'run_id' => 1, 'location_name' => 'locations/1', 'review_id' => uniqid('r', true),
            'comment' => 'Güzel', 'review_reply' => $reply !== null ? json_encode(['comment' => $reply, 'updateTime' => now()->toIso8601String()]) : null,
            'raw_payload' => '{}', 'collected_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);
    }
}
