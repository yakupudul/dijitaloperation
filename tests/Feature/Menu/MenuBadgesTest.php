<?php

namespace Tests\Feature\Menu;

use App\Models\Strategy;
use App\Models\User;
use App\Support\OperatorMenu;
use App\Support\Roles;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * Left menu (prototype approved by yakup 2026-10-20): four groups, Sistem pinned at the bottom with the Claude card,
 * badges where work waits.
 */
class MenuBadgesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleAndPermissionSeeder::class);
        $admin = User::factory()->create(['locale' => 'tr']);
        $admin->assignRole(Roles::ADMIN);
        $this->actingAs($admin);
    }

    public function test_no_badge_where_nothing_waits_and_none_for_the_removed_strategies_menu(): void
    {
        Strategy::query()->create(['title' => 'Örnek strateji', 'channel' => 'google_ads', 'status' => 'draft']);
        Strategy::query()->create(['title' => 'Uygulanan', 'channel' => 'google_ads', 'status' => 'active']);

        $badges = OperatorMenu::computeBadges();

        $this->assertArrayNotHasKey('operator.strategies', $badges, 'Stratejiler is a view of the brand page now');
        $this->assertArrayNotHasKey('operator.gbp-desk', $badges, 'no badge where nothing waits');
    }

    public function test_menu_shows_cached_badges_and_the_pinned_claude_card(): void
    {
        Cache::forever('operator-menu.badges', ['at' => now()->getTimestamp(), 'badges' => [
            'operator.google-ads-desk' => ['count' => 4, 'tone' => 'red'],
            'operator.integrations' => ['count' => null, 'tone' => 'dot'],
        ]]);

        $html = $this->get('/')->assertOk()->getContent();

        $this->assertStringContainsString('data-menu-badge="red"', $html);
        $this->assertStringContainsString('data-menu-count="4"', $html);
        $this->assertStringContainsString('data-menu-badge="dot"', $html);
        $this->assertStringContainsString('data-menu-pinned', $html);
        $this->assertStringContainsString('data-ai-live-card', $html);
        $this->assertStringContainsString('Claude hazır', $html);
        $this->assertSame(['Çalışma', 'Kanallar', 'Portföy', 'Sistem'], array_column(OperatorMenu::groups(), 'title'));
        $this->assertTrue(collect(OperatorMenu::groups())->last()['pinned']);
    }
}
