<?php

namespace Tests\Feature\Panel;

use App\Livewire\Panel\LeadsPage;
use App\Panel\Services\PanelLeads;
use App\Panel\Support\PanelStages;
use Livewire\Livewire;

/** The grouped menu (Özet, Satış, Destek, Ekip with their tabs) and the "why lost" filters of Adaylar. */
final class PanelMenuTest extends PanelTestCase
{
    public function test_manager_sees_the_grouped_menu_with_tabs(): void
    {
        $this->asPanel($this->managerA)->get($this->url('/adaylar'))->assertOk()
            ->assertSeeInOrder(['data-panel-menu', 'Özet', 'Satış', 'Destek', 'Ekip'], false)
            ->assertSeeInOrder(['data-panel-tabs', 'Adaylar', 'Gelen kutusu', 'Performans'], false)
            ->assertSee('data-panel-bottom', false)
            ->assertDontSee('Ana sayfa');
    }

    public function test_a_user_sees_only_the_entries_and_tabs_of_their_role(): void
    {
        $viewer = $this->member($this->brandA, 'izleyici@example.test', role: 'İzleyici');
        $this->asPanel($viewer)->get($this->url('/adaylar'))->assertOk()
            ->assertSeeInOrder(['data-panel-tabs', 'Adaylar', 'Performans'], false)
            ->assertDontSee('Gelen kutusu')
            ->assertDontSee('Ekip');
    }

    public function test_lost_leads_are_filtered_by_reason_and_period(): void
    {
        $leads = app(PanelLeads::class);
        $price = $leads->record($this->brandA->id, ['name' => 'Fiyat Soran', 'phone' => '0532 000 00 01'], 'call');
        $far = $leads->record($this->brandA->id, ['name' => 'Uzakta Oturan', 'phone' => '0532 000 00 02'], 'call');
        $old = $leads->record($this->brandA->id, ['name' => 'Eski Kayıt', 'phone' => '0532 000 00 03'], 'call');
        $this->enter($this->managerA, $this->brandA);
        $leads->setStage($price, PanelStages::LOST, 'price', $this->managerA->id);
        $leads->setStage($far, PanelStages::LOST, 'far', $this->managerA->id);
        $leads->setStage($old, PanelStages::LOST, 'price', $this->managerA->id);
        $old->forceFill(['created_at' => now()->subDays(60)])->save();

        Livewire::test(LeadsPage::class)->set('stage', PanelStages::LOST)->set('lostReason', 'price')
            ->assertSee('Fiyat Soran')->assertSee('Eski Kayıt')->assertDontSee('Uzakta Oturan')
            ->set('period', '30')
            ->assertSee('Fiyat Soran')->assertDontSee('Eski Kayıt');
    }
}
