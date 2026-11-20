<?php

namespace Tests\Feature\Panel;

use App\Livewire\Operator\Settings\PanelUsersPage;
use App\Livewire\Operator\Settings\RolesPage;
use App\Livewire\Operator\Settings\UsersPage as OperatorUsersPage;
use App\Livewire\Panel\LeadsPage;
use App\Livewire\Panel\PersonPage;
use App\Livewire\Panel\UsersPage;
use App\Models\User;
use App\Panel\Models\AccessRole;
use App\Panel\Models\PanelAudit;
use App\Panel\Models\PanelBrandSetting;
use App\Panel\Models\PanelEvent;
use App\Panel\Models\PanelFile;
use App\Panel\Models\PanelMembership;
use App\Panel\Models\PanelPerson;
use App\Panel\Models\PanelReminder;
use App\Panel\Models\PanelRequest;
use App\Panel\Models\PanelUser;
use App\Panel\Services\PanelAccounts;
use App\Panel\Services\PanelLeads;
use App\Panel\Support\PanelContext;
use App\Panel\Support\PanelStages;
use App\Panel\Support\PhoneKey;
use App\Support\Permissions;
use App\Support\Roles;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Livewire\Livewire;
use ZipArchive;

/** What the first version does: requests in, stages, notes, reminders, files, users, roles, export. */
class PanelFeaturesTest extends PanelTestCase
{
    private function admin(): User
    {
        $this->seed(RoleAndPermissionSeeder::class);
        $admin = User::factory()->create();
        $admin->assignRole(Roles::ADMIN);

        return $admin;
    }

    private function people(int $brandId)
    {
        return app(PanelContext::class)->system(fn () => PanelPerson::query()->where('brand_id', $brandId)->orderBy('id')->get());
    }

    public function test_phone_numbers_reduce_to_one_key(): void
    {
        $this->assertSame('5321112233', PhoneKey::of('0532 111 22 33'));
        $this->assertSame('5321112233', PhoneKey::of('+90 (532) 111-22-33'));
        $this->assertSame('5321112233', PhoneKey::of('00905321112233'));
        $this->assertNull(PhoneKey::of('123'));
    }

    public function test_the_site_form_writes_to_the_brand_of_its_token_only(): void
    {
        $token = PanelBrandSetting::forBrand($this->brandA->id)->form_token;
        $this->postJson($this->url('/api/f/'.$token), ['ad' => 'Cem Form', 'telefon' => '0544 000 11 22', 'mesaj' => 'Bilgi almak istiyorum', 'brand_id' => $this->brandB->id, 'utm_source' => 'google'])
            ->assertOk()->assertJson(['ok' => true]);
        $this->assertSame(['Ayşe Akasya', 'Cem Form'], $this->people($this->brandA->id)->pluck('name')->all());
        $this->assertSame(['Bora Begonya'], $this->people($this->brandB->id)->pluck('name')->all());

        // The same number again is the same person with a second request, not a second person.
        $this->postJson($this->url('/api/f/'.$token), ['name' => 'Cem F.', 'phone' => '+90 544 000 11 22'])->assertOk();
        $this->assertCount(2, $this->people($this->brandA->id));
        $cem = $this->people($this->brandA->id)->last();
        $this->assertSame(2, app(PanelContext::class)->system(fn (): int => PanelRequest::query()->where('person_id', $cem->id)->count()));

        $this->postJson($this->url('/api/f/'.$token), ['name' => 'Eksik'])->assertStatus(422);
        $this->postJson($this->url('/api/f/'.$token), ['name' => 'Robot', 'phone' => '0544 000 11 99', 'website' => 'http://spam'])->assertOk();
        $this->assertCount(2, $this->people($this->brandA->id));
        $this->postJson($this->url('/api/f/'.str_repeat('x', 48)), ['name' => 'Yok', 'phone' => '0544 000 11 22'])->assertNotFound();

        PanelBrandSetting::forBrand($this->brandA->id)->update(['is_enabled' => false]);
        $this->postJson($this->url('/api/f/'.$token), ['name' => 'Kapalı', 'phone' => '0544 000 11 33'])->assertNotFound();
        // The address exists only on the panel host.
        $this->postJson('http://localhost/api/f/'.$token, ['name' => 'Yanlış', 'phone' => '0544 000 11 44'])->assertNotFound();
    }

    public function test_a_stored_note_and_message_are_not_readable_in_the_database(): void
    {
        $this->enter($this->managerA, $this->brandA);
        app(PanelLeads::class)->note($this->personA, 'Fiyatı yüksek buldu, cuma aranacak', $this->managerA->id);
        $raw = \DB::table('panel_events')->where('person_id', $this->personA->id)->where('type', 'note')->value('body');
        $this->assertStringNotContainsString('cuma', (string) $raw);
        $this->assertSame('Fiyatı yüksek buldu, cuma aranacak', PanelEvent::query()->where('type', 'note')->first()->body);
    }

    public function test_adding_moving_and_closing_a_person(): void
    {
        $this->enter($this->managerA, $this->brandA);
        Livewire::test(LeadsPage::class)
            ->set('form', ['name' => 'Deniz Yeni', 'phone' => '0535 222 33 44', 'source' => 'call', 'interest' => 'Kontrol', 'message' => 'Telefonla aradı'])
            ->call('add')->assertHasNoErrors()
            ->assertSee('Deniz Yeni');
        $deniz = PanelPerson::query()->where('name', 'Deniz Yeni')->firstOrFail();
        $this->assertSame($this->brandA->id, (int) $deniz->brand_id);
        $this->assertSame(PanelStages::NEW, $deniz->stage);

        Livewire::test(LeadsPage::class)->call('move', $deniz->id, PanelStages::APPOINTMENT);
        $this->assertSame(PanelStages::APPOINTMENT, $deniz->fresh()->stage);

        // Olumsuz needs a reason.
        Livewire::test(LeadsPage::class)->call('move', $deniz->id, PanelStages::LOST)->assertSet('losing', $deniz->id)
            ->call('lose')->assertHasErrors('reason')
            ->set('reason', 'price')->call('lose')->assertHasNoErrors();
        $this->assertSame([PanelStages::LOST, 'price'], [$deniz->fresh()->stage, $deniz->fresh()->lost_reason]);

        // Another brand's person cannot be moved.
        Livewire::test(LeadsPage::class)->call('move', $this->personB->id, PanelStages::CUSTOMER);
        $this->assertSame(PanelStages::NEW, app(PanelContext::class)->system(fn () => PanelPerson::query()->find($this->personB->id)->stage));

        // Asking again after a closed record opens it as new.
        app(PanelLeads::class)->record($this->brandA->id, ['name' => 'Deniz', 'phone' => '0535 222 33 44'], 'web_form');
        $this->assertSame(PanelStages::NEW, $deniz->fresh()->stage);
    }

    public function test_the_person_page_keeps_notes_reminders_and_files(): void
    {
        Storage::fake('local');
        $this->enter($this->managerA, $this->brandA);
        $page = Livewire::test(PersonPage::class, ['person' => $this->personA->id])
            ->set('note', 'Salı günü tekrar aranacak')->call('addNote')->assertHasNoErrors()->assertSee('Salı günü tekrar aranacak')
            ->set('reminder.title', 'Tekrar ara')->set('reminder.due', now('Europe/Istanbul')->addDay()->format('Y-m-d\TH:i'))->call('addReminder')->assertHasNoErrors()
            ->call('edit')->set('form.city', 'Manisa')->set('form.birth_year', '1990')->call('save')->assertHasNoErrors()
            ->set('upload', UploadedFile::fake()->create('tahlil.pdf', 40, 'application/pdf'))->assertHasNoErrors();
        $this->assertSame('Manisa', $this->personA->fresh()->city);
        $this->assertSame(1, PanelReminder::query()->count());
        $file = PanelFile::query()->firstOrFail();
        Storage::disk('local')->assertExists($file->path);
        $page->call('closeReminder', PanelReminder::query()->value('id'));
        $this->assertNotNull(PanelReminder::query()->first()->done_at);

        $this->get($this->url('/dosya/'.$file->id))->assertOk();
        $this->get($this->url('/adaylar/'.$this->personB->id))->assertNotFound();
    }

    public function test_a_role_decides_what_a_user_sees_and_does(): void
    {
        $viewer = $this->member($this->brandA, 'izleyen@example.test', role: 'İzleyici');
        $this->asPanel($viewer);
        $this->get($this->url('/adaylar'))->assertOk()->assertSee('Ayşe Akasya')->assertDontSee('0532 111 22 33')->assertDontSee('Aday ekle')->assertDontSee('Excel');
        $this->get($this->url('/kullanicilar'))->assertForbidden();
        $this->get($this->url('/gelen-kutusu'))->assertForbidden();
        $this->get($this->url('/performans'))->assertOk();
        $this->get($this->url('/disa-aktar/adaylar'))->assertForbidden();

        $this->enter($viewer, $this->brandA);
        Livewire::test(LeadsPage::class)->call('move', $this->personA->id, PanelStages::CUSTOMER)->assertForbidden();
        Livewire::test(LeadsPage::class)->set('form', ['name' => 'X', 'phone' => '0535 000 00 00', 'source' => 'call'])->call('add')->assertForbidden();
        Livewire::test(PersonPage::class, ['person' => $this->personA->id])->set('note', 'yazamaz')->call('addNote')->assertForbidden();
        Livewire::test(UsersPage::class)->assertForbidden();
    }

    public function test_a_user_without_all_leads_sees_only_their_own(): void
    {
        $role = AccessRole::query()->create(['scope' => 'panel', 'name' => 'Kendi adayları', 'permissions' => ['menu.home', 'menu.leads', 'leads.phone', 'leads.stage']]);
        $seller = $this->member($this->brandA, 'satis@example.test');
        PanelMembership::query()->where('panel_user_id', $seller->id)->update(['access_role_id' => $role->id]);
        $mine = app(PanelLeads::class)->record($this->brandA->id, ['name' => 'Benim Adayım', 'phone' => '0536 111 00 00', 'assigned_user_id' => $seller->id], 'call');

        $this->asPanel($seller);
        $this->get($this->url('/adaylar'))->assertOk()->assertSee('Benim Adayım')->assertDontSee('Ayşe Akasya');
        $this->get($this->url('/'))->assertOk()->assertSee('Benim Adayım')->assertDontSee('Ayşe Akasya');
        $this->get($this->url('/adaylar/'.$mine->id))->assertOk();
        $this->get($this->url('/adaylar/'.$this->personA->id))->assertNotFound();
    }

    public function test_only_the_manager_exports_and_the_export_is_logged(): void
    {
        $seller = $this->member($this->brandA, 'satis@example.test', role: 'Satış');
        $this->asPanel($seller)->get($this->url('/disa-aktar/adaylar'))->assertForbidden();
        $this->asPanel($seller)->get($this->url('/adaylar'))->assertOk()->assertDontSee('data-panel-export', false);

        auth()->forgetGuards();
        $this->flushSession();
        $response = $this->asPanel($this->managerA)->get($this->url('/disa-aktar/adaylar'));
        $response->assertOk();
        $zip = new ZipArchive;
        $this->assertTrue($zip->open($response->baseResponse->getFile()->getPathname()));
        $sheet = (string) $zip->getFromName('xl/worksheets/sheet1.xml');
        $zip->close();
        $this->assertStringContainsString('Ayşe Akasya', $sheet);
        $this->assertStringContainsString('0532 111 22 33', $sheet);
        $this->assertStringNotContainsString('Bora Begonya', $sheet);
        $this->assertSame(1, PanelAudit::query()->where('action', 'export.leads')->where('brand_id', $this->brandA->id)->count());
    }

    public function test_the_manager_adds_a_user_who_must_set_a_password_first(): void
    {
        $this->enter($this->managerA, $this->brandA);
        $roleId = AccessRole::query()->where('scope', 'panel')->where('name', 'Satış')->value('id');
        $page = Livewire::test(UsersPage::class)->set('name', 'Selin Satış')->set('email', 'Selin@Example.test')->set('roleId', (string) $roleId)->call('add')->assertHasNoErrors();
        $password = $page->get('handover')['password'];
        $this->assertNotEmpty($password);
        $selin = PanelUser::query()->where('email', 'selin@example.test')->firstOrFail();
        $this->assertTrue($selin->must_change_password);
        $this->assertSame($this->brandA->id, (int) PanelMembership::query()->where('panel_user_id', $selin->id)->value('brand_id'));

    }

    public function test_a_new_user_signs_in_and_sets_a_password_before_anything_else(): void
    {
        $roleId = AccessRole::query()->where('scope', 'panel')->where('name', 'Satış')->value('id');
        [$membership, $password] = app(PanelAccounts::class)->add($this->brandA->id, 'Selin Satış', 'selin@example.test', $roleId, false);
        $selin = $membership->user;
        $this->post($this->url('/giris'), ['email' => 'selin@example.test', 'password' => $password])->assertRedirect();
        $this->get($this->url('/adaylar'))->assertRedirect($this->url('/sifre'));
        $this->post($this->url('/sifre'), ['current' => 'wrong', 'password' => 'yeni-sifre-123', 'password_confirmation' => 'yeni-sifre-123'])->assertSessionHasErrors('current');
        $this->post($this->url('/sifre'), ['current' => $password, 'password' => 'yeni-sifre-123', 'password_confirmation' => 'yeni-sifre-123'])->assertRedirect();
        $this->assertFalse($selin->fresh()->must_change_password);
        $this->get($this->url('/adaylar'))->assertOk()->assertSee('Ayşe Akasya');
        $this->get($this->url('/kullanicilar'))->assertForbidden();
    }

    public function test_a_shared_account_is_not_reset_by_one_brand(): void
    {
        $shared = $this->member($this->brandA, 'ortak@example.test', role: 'Satış');
        $this->member($this->brandB, 'ortak@example.test', role: 'Satış');
        $hash = $shared->fresh()->password;
        $this->enter($this->managerA, $this->brandA);
        Livewire::test(UsersPage::class)->call('renew', PanelMembership::query()->where('panel_user_id', $shared->id)->where('brand_id', $this->brandA->id)->value('id'))->assertSet('handover', null);
        $this->assertSame($hash, $shared->fresh()->password);
        // And brand B's membership cannot be touched from brand A.
        Livewire::test(UsersPage::class)->call('toggle', PanelMembership::query()->where('brand_id', $this->brandB->id)->where('panel_user_id', $shared->id)->value('id'));
        $this->assertTrue((bool) PanelMembership::query()->where('brand_id', $this->brandB->id)->where('panel_user_id', $shared->id)->value('is_active'));
    }

    public function test_the_admin_manages_brand_users_and_roles_in_moxdop(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin);
        $this->get('http://localhost/settings/panel-users')->assertOk()->assertSee('Akasya')->assertDontSee('Ayşe Akasya');
        $this->get('http://localhost/settings/roles')->assertOk()->assertSee('Satış');

        $page = Livewire::test(PanelUsersPage::class)->call('open', $this->brandA->id)
            ->assertSee('a@example.test')->assertDontSee('b@example.test')->assertDontSee('Ayşe Akasya')->assertDontSee('0532 111 22 33')
            ->set('name', 'Yeni Yönetici')->set('email', 'yeni@example.test')->set('asManager', true)->call('add')->assertHasNoErrors();
        $this->assertNotEmpty($page->get('handover')['password']);
        $this->assertTrue((bool) PanelMembership::query()->where('brand_id', $this->brandA->id)->whereHas('user', fn ($q) => $q->where('email', 'yeni@example.test'))->value('is_manager'));

        Livewire::test(RolesPage::class)->call('create')->set('name', 'Hekim')->set('permissions', ['menu.home', 'menu.leads', 'leads.all', 'not.a.permission'])->call('save')->assertHasNoErrors();
        $this->assertSame(['menu.home', 'menu.leads', 'leads.all'], AccessRole::query()->where('name', 'Hekim')->firstOrFail()->permissions);
        // A role in use is not removed.
        $used = AccessRole::query()->where('scope', 'panel')->where('name', 'Satış')->firstOrFail();
        $this->member($this->brandA, 'satis@example.test', role: 'Satış');
        Livewire::test(RolesPage::class)->call('remove', $used->id);
        $this->assertNotNull($used->fresh());
    }

    public function test_view_as_needs_a_signed_link_from_an_admin(): void
    {
        $admin = $this->admin();
        $membership = PanelMembership::query()->where('panel_user_id', $this->managerA->id)->firstOrFail();
        $this->get($this->url('/gir/'.$membership->id.'/'.$admin->id))->assertForbidden();

        $link = URL::temporarySignedRoute('panel.view-as', now()->addMinute(), ['membership' => $membership->id, 'operator' => $admin->id]);
        $this->get($link)->assertRedirect();
        $this->get($this->url('/adaylar'))->assertOk()->assertSee('Ayşe Akasya')->assertSee('data-panel-viewer', false);
        $this->assertSame(1, PanelAudit::query()->where('action', 'view_as')->where('actor_type', 'operator')->count());

        $member = User::factory()->create();
        $member->assignRole(Roles::TEAM_MEMBER);
        auth()->forgetGuards();
        $this->get(URL::temporarySignedRoute('panel.view-as', now()->addMinute(), ['membership' => $membership->id, 'operator' => $member->id]))->assertForbidden();
    }

    public function test_a_moxdop_role_limits_a_team_member_to_its_menus(): void
    {
        $this->admin();
        $member = User::factory()->create();
        $member->assignRole(Roles::TEAM_MEMBER);
        $member->givePermissionTo(Permissions::ACCESS_APP);

        // Without a role nothing changes.
        $this->actingAs($member)->get('http://localhost/brands')->assertOk();
        $this->actingAs($member)->get('http://localhost/settings/users')->assertOk();
        $this->actingAs($member)->get('http://localhost/settings/roles')->assertForbidden();
        $this->actingAs($member)->get('http://localhost/settings/panel-users')->assertForbidden();

        $role = AccessRole::query()->where('scope', 'moxdop')->where('name', 'Ekip üyesi')->firstOrFail();
        $this->actingAs($this->admin());
        Livewire::test(OperatorUsersPage::class)->call('setAccessRole', $member->id, (string) $role->id);
        $this->assertSame($role->id, (int) $member->fresh()->access_role_id);

        $member = $member->fresh();
        $this->actingAs($member)->get('http://localhost/brands')->assertOk()->assertDontSee('href="/integrations"', false);
        $this->actingAs($member)->get('http://localhost/settings/users')->assertForbidden();
        $this->actingAs($member)->get('http://localhost/integrations')->assertForbidden();
        $this->actingAs($member)->get('http://localhost/satis')->assertForbidden();
    }

    public function test_ready_lists_are_written_once_picked_afterwards_and_stay_in_the_brand(): void
    {
        $this->enter($this->managerA, $this->brandA);
        $presets = app(\App\Panel\Services\PanelPresets::class);

        // A topic added from the form is on the list, next to the topics already written on people; no doubles.
        \Livewire\Livewire::test(\App\Livewire\Panel\LeadsPage::class)->set('adding', true)->call('addInterest', '  Ücretsiz  ön görüşme ')
            ->assertSet('form.interest', 'Ücretsiz ön görüşme')->call('addInterest', 'ücretsiz ön görüşme');
        $this->assertSame(['Ücretsiz ön görüşme', 'Kontrol'], $presets->interests()->all());

        // A note is kept as a template and comes back into the note box; a second save form stays open.
        \Livewire\Livewire::test(\App\Livewire\Panel\PersonPage::class, ['person' => $this->personA->id])
            ->set('note', "Arandı, ulaşılamadı.\nYarın tekrar aranacak.")->call('saveTemplate')
            ->set('note', '')->call('useTemplate', (string) \App\Panel\Models\PanelPreset::query()->where('kind', 'note')->value('id'))
            ->assertSet('note', "Arandı, ulaşılamadı.\nYarın tekrar aranacak.")
            ->call('remindIn', 7)->assertSet('reminder.due', now('Europe/Istanbul')->addDays(7)->setTime(10, 0)->format('Y-m-d\TH:i'));
        $this->assertSame('Arandı, ulaşılamadı.', \App\Panel\Models\PanelPreset::query()->where('kind', 'note')->value('label'));

        // The ready message carries the person's first name; the lists page adds and the manager removes.
        \Livewire\Livewire::test(\App\Livewire\Panel\PresetsPage::class)
            ->set('draft.message.label', 'Teyit mesajı')->set('draft.message.body', 'Merhaba {ad}, yarın bekliyoruz.')->call('add', 'message')->assertHasNoErrors()
            ->set('draft.note.label', 'Boş')->call('add', 'note')->assertHasErrors('draft.note.body');
        $this->assertSame('Merhaba Ayşe, yarın bekliyoruz.', \App\Panel\Services\PanelPresets::fill('Merhaba {ad}, yarın bekliyoruz.', $this->personA->name));
        $this->get($this->url('/adaylar/'.$this->personA->id))->assertOk();
        \Livewire\Livewire::test(\App\Livewire\Panel\PersonPage::class, ['person' => $this->personA->id])->assertSee('Hazır mesaj')->assertSee('Merhaba%20Ay%C5%9Fe', false);

        // "Kaydet ve yenisini ekle" keeps the form open for the next caller.
        \Livewire\Livewire::test(\App\Livewire\Panel\LeadsPage::class)->set('adding', true)
            ->set('form.name', 'Cem Akasya')->set('form.phone', '0535 777 88 99')->set('form.source', 'whatsapp')->call('add', true)
            ->assertSet('adding', true)->assertSet('form.name', '')->assertSet('form.source', 'whatsapp');

        // The other brand sees none of it, and a non-manager cannot remove.
        $this->enter($this->managerB, $this->brandB);
        $this->assertSame(['Bilgi'], $presets->interests()->all());
        $this->assertSame(0, \App\Panel\Models\PanelPreset::query()->count());
        \Livewire\Livewire::test(\App\Livewire\Panel\PresetsPage::class)->assertDontSee('Teyit mesajı');
        $sales = $this->member($this->brandA, 'satis@example.test', role: 'Satış');
        $this->enter($sales, $this->brandA);
        $id = (int) \App\Panel\Models\PanelPreset::query()->where('kind', 'message')->value('id');
        \Livewire\Livewire::test(\App\Livewire\Panel\PresetsPage::class)->assertSee('Teyit mesajı')->call('remove', $id)->assertForbidden();
        $this->enter($this->managerA, $this->brandA);
        \Livewire\Livewire::test(\App\Livewire\Panel\PresetsPage::class)->call('remove', $id);
        $this->assertSame(0, \App\Panel\Models\PanelPreset::query()->where('kind', 'message')->count());
    }
}
