<?php

namespace Tests\Feature\Panel;

use App\Livewire\Operator\Settings\PanelSupportPage;
use App\Livewire\Panel\Bell;
use App\Livewire\Panel\SupportPage;
use App\Models\Reminder;
use App\Models\User;
use App\Panel\Models\AccessRole;
use App\Panel\Models\PanelAnnouncement;
use App\Panel\Models\PanelNotification;
use App\Panel\Models\PanelTicket;
use App\Panel\Services\PanelLeads;
use App\Panel\Support\PanelContext;
use App\Panel\Support\PanelPermissions;
use App\Support\Roles;
use Database\Seeders\RoleAndPermissionSeeder;
use Livewire\Livewire;

/** Sürüm 2: support requests, announcements and the bell stay inside their brand and reach the right people. */
class PanelSupportTest extends PanelTestCase
{
    private function admin(): User
    {
        $this->seed(RoleAndPermissionSeeder::class);
        $admin = User::factory()->create(['is_active' => true]);
        $admin->assignRole(Roles::ADMIN);

        return $admin;
    }

    public function test_a_request_reaches_the_agency_and_the_answer_rings_the_bell(): void
    {
        $admin = $this->admin();
        $this->enter($this->managerA, $this->brandA);
        Livewire::test(SupportPage::class)->set('adding', true)
            ->set('form.subject', 'Formu bağlayın')->set('form.category', 'connection')->set('form.body', 'Sitemizdeki iletişim formu panele düşsün.')
            ->call('open')->assertHasNoErrors()->assertSee('Formu bağlayın');
        $ticket = PanelTicket::query()->firstOrFail();
        $this->assertSame(PanelTicket::OPEN, $ticket->status);
        $this->assertTrue(Reminder::query()->where('user_id', $admin->id)->where('title', 'like', '%Akasya%Formu bağlayın%')->exists(), 'the request is on the operator\'s Yapılacaklar');

        // The other brand does not see it; the agency sees it in its one box and answers.
        $this->enter($this->managerB, $this->brandB);
        Livewire::test(SupportPage::class)->assertDontSee('Formu bağlayın')->set('ticketId', $ticket->id)->set('reply', 'x')->call('send')->assertNotFound();
        app(PanelContext::class)->leave();
        $this->actingAs($admin);
        Livewire::test(PanelSupportPage::class)->assertSee('Formu bağlayın')->assertSee('Akasya')
            ->set('ticketId', $ticket->id)->set('reply', 'Bugün bağlıyoruz.')->call('send')->assertHasNoErrors();
        $this->assertSame(PanelTicket::ANSWERED, app(PanelContext::class)->system(fn () => $ticket->fresh()->status));

        $this->enter($this->managerA, $this->brandA);
        Livewire::test(Bell::class)->assertSee('Talebiniz cevaplandı: Formu bağlayın');
        Livewire::test(SupportPage::class)->set('ticketId', $ticket->id)->assertSee('Bugün bağlıyoruz.')->call('solve');
        $this->assertSame(PanelTicket::SOLVED, $ticket->fresh()->status);
        $this->enter($this->managerB, $this->brandB);
        Livewire::test(Bell::class)->assertDontSee('Formu bağlayın');
    }

    public function test_an_announcement_goes_to_everyone_or_to_one_brand(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin);
        Livewire::test(PanelSupportPage::class)->set('tab', 'duyurular')
            ->set('form.title', 'Bayram çalışma saatleri')->set('form.body', 'Destek hattı bayramda açık.')->call('announce')->assertHasNoErrors()
            ->set('form.title', 'Akasya için not')->set('form.body', 'Yeni raporunuz hazır.')->set('form.target', 'brand')->set('form.brand_id', (string) $this->brandA->id)->call('announce')->assertHasNoErrors();

        $this->enter($this->managerA, $this->brandA);
        Livewire::test(SupportPage::class)->set('tab', 'duyurular')->assertSee('Bayram çalışma saatleri')->assertSee('Akasya için not');
        Livewire::test(Bell::class)->assertSee('Duyuru: Akasya için not');
        $this->enter($this->managerB, $this->brandB);
        Livewire::test(SupportPage::class)->set('tab', 'duyurular')->assertSee('Bayram çalışma saatleri')->assertDontSee('Akasya için not');

        // Withdrawn: gone from every panel.
        app(PanelContext::class)->leave();
        $this->actingAs($admin);
        Livewire::test(PanelSupportPage::class)->call('withdraw', PanelAnnouncement::query()->where('title', 'Bayram çalışma saatleri')->value('id'));
        $this->enter($this->managerB, $this->brandB);
        Livewire::test(SupportPage::class)->set('tab', 'duyurular')->assertDontSee('Bayram çalışma saatleri');
    }

    public function test_a_request_from_the_site_form_rings_the_bell_of_those_who_see_leads(): void
    {
        $seller = $this->member($this->brandA, 'satis@example.test', role: 'Satış');
        $none = $this->member($this->brandA, 'yok@example.test');
        app(PanelLeads::class)->record($this->brandA->id, ['name' => 'Deniz Form', 'phone' => '0535 222 33 44'], 'web_form');
        $who = app(PanelContext::class)->system(fn () => PanelNotification::query()->where('title', 'like', 'Yeni talep: Deniz Form%')->pluck('panel_user_id')->all());
        $this->assertEqualsCanonicalizing([$this->managerA->id, $seller->id], $who);
        $this->assertNotContains($none->id, $who);

        $this->enter($seller, $this->brandA);
        Livewire::test(Bell::class)->assertSee('Yeni talep: Deniz Form')->call('readAll');
        $this->assertSame(0, PanelNotification::query()->where('panel_user_id', $seller->id)->whereNull('read_at')->count());
        // A typed-in call rings nobody: the one who typed it already knows.
        app(PanelLeads::class)->record($this->brandA->id, ['name' => 'Elle Yazılan', 'phone' => '0535 222 33 55'], 'call', $seller->id);
        $this->assertSame(0, PanelNotification::query()->where('title', 'like', '%Elle Yazılan%')->count());
    }

    public function test_new_permissions_reach_the_starting_roles_once_and_a_user_can_be_set_apart(): void
    {
        // A role made before the new keys existed: it gets what its default names, once.
        $sales = AccessRole::query()->where('scope', PanelPermissions::SCOPE_PANEL)->where('name', 'Satış')->firstOrFail();
        $sales->forceFill(['permissions' => ['menu.home', 'menu.leads'], 'seen_keys' => null])->save();
        AccessRole::ensureDefaults();
        $this->assertContains('support.write', $sales->fresh()->permissions);
        $this->assertNotContains('import.leads', $sales->fresh()->permissions);
        // Taken away by the admin afterwards: it stays away.
        $sales->fresh()->forceFill(['permissions' => ['menu.home', 'menu.leads']])->save();
        AccessRole::ensureDefaults();
        $this->assertNotContains('support.write', $sales->fresh()->permissions);

        $seller = $this->member($this->brandA, 'satis@example.test', role: 'Satış');
        $membership = \App\Panel\Models\PanelMembership::query()->where('panel_user_id', $seller->id)->firstOrFail();
        $this->assertFalse($membership->allows('menu.performance'));
        $membership->forceFill(['overrides' => ['allow' => ['menu.performance'], 'deny' => ['menu.leads']]])->save();
        $this->assertTrue($membership->fresh()->load('role')->allows('menu.performance'));
        $this->assertFalse($membership->fresh()->load('role')->allows('menu.leads'));
    }

    public function test_a_user_who_only_reads_cannot_open_a_request(): void
    {
        $viewer = $this->member($this->brandA, 'izle@example.test', role: 'İzleyici');
        \App\Panel\Models\PanelMembership::query()->where('panel_user_id', $viewer->id)->firstOrFail()->forceFill(['overrides' => ['allow' => ['menu.support']]])->save();
        $this->enter($viewer, $this->brandA);
        Livewire::test(SupportPage::class)->assertDontSee('Talep aç')->set('form.subject', 'x')->set('form.body', 'y')->call('open')->assertForbidden();
    }
}
