<?php

namespace Tests\Feature\Brand;

use App\Ai\Agents\BrandCareAgent;
use App\Ai\Agents\BrandChiefAgent;
use App\Enums\CustomerStatus;
use App\Jobs\Brand\RunBrandCareJob;
use App\Jobs\Brand\RunBrandChiefJob;
use App\Livewire\Demo\Dashboard;
use App\Livewire\Operator\Workspace\BrandDossierTab;
use App\Models\Brand;
use App\Models\ChiefPlan;
use App\Models\Customer;
use App\Models\Suggestion;
use App\Models\User;
use App\Models\UserNotification;
use App\Services\Brand\BrandCare;
use App\Services\Brand\BrandChief;
use App\Services\Brand\BrandDossier;
use App\Support\Roles;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\TestCase;

/** Marka bakım ajanı + Şef: delta-driven weekly review of active brands; one Monday plan from the care notes. */
final class BrandCareTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Brand $brand;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleAndPermissionSeeder::class);
        config(['moxdop.anthropic.api_key' => 'test-anthropic-value']);
        $this->admin = User::factory()->create(['is_active' => true]);
        $this->admin->assignRole(Roles::ADMIN);
        $this->actingAs($this->admin);
        $this->brand = $this->activeBrand('Adadent');
    }

    public function test_it_reviews_only_when_the_dossier_changed_and_keeps_the_work_list_clean(): void
    {
        Suggestion::query()->forceCreate($this->openSuggestion('Ana sayfa başlığını düzelt'));
        $calls = 0;
        BrandCareAgent::fake(function () use (&$calls): array {
            $calls++;

            return $calls === 1 ? [
                'summary' => 'Hizmet sayfaları eksik.',
                'tasks' => [
                    ['title' => 'İmplant sayfası oluştur', 'why' => 'Hizmetin sayfası yok.', 'channel' => 'search', 'priority' => 1],
                    ['title' => 'Ana sayfa başlığını düzelt', 'why' => 'zaten açık', 'channel' => 'search', 'priority' => 2],
                    ['title' => 'TikTok hesabı aç', 'why' => 'x', 'channel' => 'tiktok', 'priority' => 1],
                ],
                'questions' => ['Kadıköy şubesi hâlâ açık mı?'],
            ] : [
                'summary' => 'Hedef eklendi.',
                'tasks' => [['title' => 'Kadıköy için Harita gönderisi paylaş', 'why' => 'Hedef Kadıköy.', 'channel' => 'maps', 'priority' => 1]],
                'questions' => [],
            ];
        });
        $care = app(BrandCare::class);

        $this->assertSame('reviewed', $care->run($this->brand)['status']);
        $task = Suggestion::query()->where('decision_key', BrandCare::DECISION)->sole();
        $this->assertSame(['İmplant sayfası oluştur', 'search', Suggestion::OPEN, 'brand'], [$task->title, $task->channel, $task->status, $task->target_type]);
        $this->assertSame(['Kadıköy şubesi hâlâ açık mı?'], BrandCare::stored($this->brand)['questions']);

        $this->assertSame('unchanged', $care->run($this->brand)['status'], 'nothing changed: no AI call');
        $this->assertSame(1, $calls);

        BrandDossier::saveNotes($this->brand, 'Kadıköy öne çıksın', '');
        $result = $care->run($this->brand);
        $this->assertSame(['reviewed', ['notes']], [$result['status'], $result['changed']]);
        $this->assertSame(2, $calls);
        $this->assertSame(Suggestion::DISMISSED, $task->fresh()->status, 'no longer proposed: closed');
        $this->assertSame(['Kadıköy için Harita gönderisi paylaş'], Suggestion::query()->where('decision_key', BrandCare::DECISION)->actionable()->pluck('title')->all());

        $this->travel(BrandCare::FULL_REVIEW_DAYS + 1)->days();
        $this->assertSame('reviewed', $care->run($this->brand)['status'], 'a full look again after four weeks');
    }

    public function test_dismissed_tasks_never_come_back_and_passive_brands_are_skipped(): void
    {
        BrandCareAgent::fake(fn (): array => ['summary' => 's', 'tasks' => [['title' => 'İmplant sayfası oluştur', 'why' => 'y', 'channel' => 'search', 'priority' => 1]], 'questions' => []]);
        $care = app(BrandCare::class);
        $care->run($this->brand);
        Suggestion::query()->where('decision_key', BrandCare::DECISION)->update(['status' => Suggestion::DISMISSED]);

        $care->run($this->brand, force: true);
        $this->assertSame(0, Suggestion::query()->where('decision_key', BrandCare::DECISION)->actionable()->count());

        $passive = Brand::factory()->create(['customer_id' => Customer::factory()->create(['status' => CustomerStatus::Inactive->value])->id]);
        $this->assertSame('not_operational', $care->run($passive)['status']);
    }

    public function test_the_weekly_command_queues_active_brands_and_the_tab_shows_the_note(): void
    {
        Queue::fake();
        Brand::factory()->create(['customer_id' => Customer::factory()->create(['status' => CustomerStatus::Inactive->value])->id]);
        $this->artisan('moxdop:brands:care')->assertSuccessful();
        Queue::assertPushed(RunBrandCareJob::class, 1);

        // A second brand: the weekly job of the first one still holds its unique lock (a click while it waits is deduplicated).
        $brand = $this->activeBrand('Cdent');
        BrandCareAgent::fake(fn (): array => ['summary' => 'Sayfalar iyi, Harita zayıf.', 'tasks' => [['title' => 'Profil açıklamasını güncelle', 'why' => 'Açıklama eski.', 'channel' => 'maps', 'priority' => 2]], 'questions' => ['Hafta sonu açık mısınız?']]);
        app(BrandCare::class)->run($brand);
        Livewire::test(BrandDossierTab::class, ['brandId' => $brand->id])
            ->assertSee('Sayfalar iyi, Harita zayıf.')->assertSee('Profil açıklamasını güncelle')->assertSee('Hafta sonu açık mısınız?')
            ->call('reviewNow')->assertSee('Bakım ajanı sıraya alındı');
        Queue::assertPushed(RunBrandCareJob::class, fn (RunBrandCareJob $job): bool => $job->force && $job->brandId === $brand->id);
    }

    public function test_the_chief_plans_the_week_from_the_care_notes(): void
    {
        $other = $this->activeBrand('Bdent');
        BrandChiefAgent::fake([[
            'headline' => 'Harita haftası.',
            'plan' => [
                ['brand_id' => 0, 'task' => 'Hata merkezindeki bağlantıyı yenile', 'why' => 'Veri durdu.'],
                ['brand_id' => $this->brand->id, 'task' => 'Görev 1', 'why' => 'a'],
                ['brand_id' => $this->brand->id, 'task' => 'Görev 2', 'why' => 'b'],
                ['brand_id' => $this->brand->id, 'task' => 'Görev 3', 'why' => 'c'],
                ['brand_id' => $this->brand->id, 'task' => 'Görev 4', 'why' => 'fazla'],
                ['brand_id' => 999, 'task' => 'Yok', 'why' => 'bilinmeyen marka'],
                ['brand_id' => $other->id, 'task' => 'Bdent görevi', 'why' => 'd'],
            ],
        ]]);

        $this->assertSame(['status' => 'ready', 'lines' => 5], app(BrandChief::class)->run());
        $plan = ChiefPlan::query()->sole();
        $this->assertSame(BrandChief::weekStart()->toDateString(), $plan->week_start->toDateString());
        $this->assertSame(['Hata merkezi', 'Adadent', 'Adadent', 'Adadent', 'Bdent'], array_column($plan->plan, 'brand'));
        $this->assertSame('Şef: bu haftanın planı hazır (5 iş)', UserNotification::query()->sole()->presentation['title']);

        Queue::fake();
        Livewire::test(Dashboard::class)->assertSeeHtml('data-chief-plan')->assertSee('Harita haftası.')->assertSee('Bdent görevi')
            ->call('refreshPlan')->assertDispatched('operator-notice');
        Queue::assertPushed(RunBrandChiefJob::class, fn (RunBrandChiefJob $job): bool => ! $job->notify);
    }

    private function activeBrand(string $name): Brand
    {
        return Brand::factory()->create(['name' => $name, 'customer_id' => Customer::factory()->create(['status' => CustomerStatus::Active->value])->id]);
    }

    /** @return array<string, mixed> */
    private function openSuggestion(string $title): array
    {
        return ['brand_id' => $this->brand->id, 'channel' => 'search', 'decision_key' => 'site.x', 'fingerprint' => hash('sha256', $title), 'material_hash' => hash('sha256', $title),
            'title' => $title, 'reason' => 'r', 'priority' => 2, 'action_type' => 'x', 'status' => Suggestion::OPEN, 'first_seen_at' => now(), 'last_seen_at' => now()];
    }
}
