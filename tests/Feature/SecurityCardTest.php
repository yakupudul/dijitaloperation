<?php

namespace Tests\Feature;

use App\Livewire\Operator\Settings\SecurityCardPage;
use App\Models\McpToken;
use App\Models\User;
use App\Services\Mcp\McpKeys;
use App\Services\Security\SecurityCard;
use App\Support\Roles;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/** Güvenlik karnesi (Bağlantı Düzeni 3.5): one question per row, red first, nothing secret on the page. */
final class SecurityCardTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_card_answers_who_can_get_in_and_what_is_lost_with_the_server(): void
    {
        config(['app.debug' => false]);
        $this->seed(RoleAndPermissionSeeder::class);
        $admin = User::factory()->create(['is_active' => true, 'name' => 'Yakup', 'email' => 'staging-admin@moxdop.local', 'last_login_at' => now()]);
        $admin->assignRole(Roles::ADMIN);
        $this->actingAs($admin);
        $plain = McpKeys::issue('Eski deneme', McpToken::WRITE, $admin->id);
        McpToken::query()->update(['created_at' => now()->subDays(40)]);

        $rows = collect(app(SecurityCard::class)->rows())->keyBy('key');
        $this->assertSame('bad', $rows['two_factor']['tone']);
        $this->assertStringContainsString('Yakup', $rows['two_factor']['detail']);
        $this->assertSame('bad', $rows['login_address']['tone'], 'a .local address cannot receive a reset mail');
        $this->assertSame('warn', $rows['keys']['tone'], 'a key nobody uses is asked about');
        $this->assertSame('ok', $rows['debug']['tone']);
        $this->assertSame('ok', $rows['emergency_stop']['tone']);

        $page = Livewire::test(SecurityCardPage::class)->assertSee('Güvenlik karnesi')->assertSee('İki adımlı doğrulama')->assertSee('Eski deneme')
            ->assertDontSee($plain)->assertDontSee('staging-admin@moxdop.local');
        $html = $page->html();
        $this->assertLessThan(strpos($html, 'data-tone="ok"'), strpos($html, 'data-tone="bad"'), 'red rows come first');

        $admin->forceFill(['email' => 'yakup@moximu.com'])->save();
        $this->assertNotSame('bad', collect(app(SecurityCard::class)->rows())->keyBy('key')['login_address']['tone']);

        $this->actingAs(User::factory()->create(['is_active' => true]));
        Livewire::test(SecurityCardPage::class)->assertForbidden();
    }
}
