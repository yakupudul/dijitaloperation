<?php

namespace Tests\Feature\Panel;

use App\Livewire\Panel\PerformancePage;
use App\Panel\Services\PanelLeads;
use App\Panel\Services\PanelPerformance;
use App\Panel\Support\PanelStages;
use Livewire\Livewire;

/** Sürüm 2: Performans › Satış counts the brand's own people, and staff comparison is for those allowed to see it. */
class PanelPerformanceTest extends PanelTestCase
{
    public function test_the_funnel_sources_and_response_time_come_from_the_records(): void
    {
        $leads = app(PanelLeads::class);
        $this->enter($this->managerA, $this->brandA);
        $second = $leads->record($this->brandA->id, ['name' => 'Cem Can', 'phone' => '0535 000 00 02', 'interest' => 'Kontrol'], 'referral', $this->managerA->id);
        $third = $leads->record($this->brandA->id, ['name' => 'Derya Dal', 'phone' => '0535 000 00 03'], 'web_form', $this->managerA->id);
        // Ayşe: reached after 30 minutes, then appointment, came, customer. Cem: lost on price. Derya: untouched.
        $this->travel(30)->minutes();
        foreach ([PanelStages::REACHED, PanelStages::APPOINTMENT, PanelStages::CAME, PanelStages::CUSTOMER] as $stage) {
            $leads->setStage($this->personA->fresh(), $stage, null, $this->managerA->id);
        }
        $leads->setStage($second, PanelStages::APPOINTMENT, null, $this->managerA->id);
        $leads->setStage($second->fresh(), PanelStages::LOST, 'price', $this->managerA->id);

        $data = app(PanelPerformance::class)->sales($leads->visible(), now()->subDay(), now()->addDay());
        $this->assertSame([3, 2, 2, 1, 1], array_column($data['funnel'], 'value'), 'a lost person still counts for the steps they reached');
        $this->assertSame(30, $data['response']);
        $this->assertSame(1, $data['unanswered']);
        $this->assertSame([['label' => 'Fiyat', 'value' => 1]], $data['lost']);
        $web = collect($data['sources'])->firstWhere('label', 'Web formu');
        $this->assertSame([2, 1, 1, 50], [$web['leads'], $web['appointments'], $web['customers'], $web['rate']]);
        $this->assertSame('1 sa 30 dk', PanelPerformance::duration(90));

        Livewire::test(PerformancePage::class)->assertSee('Adaydan müşteriye')->assertSee('Kim ne kadar ilgilendi')->assertSee('Fiyat')->assertDontSee('Bilgi')
            ->set('tab', 'google')->assertSee('henüz açık değil');
        $this->enter($this->managerB, $this->brandB);
        Livewire::test(PerformancePage::class)->assertDontSee('Kontrol')->assertSee('Bilgi');
    }

    public function test_staff_comparison_needs_its_permission(): void
    {
        $viewer = $this->member($this->brandA, 'izleyen@example.test', role: 'İzleyici');
        $this->enter($viewer, $this->brandA);
        Livewire::test(PerformancePage::class)->assertSee('Adaydan müşteriye')->assertDontSee('Kim ne kadar ilgilendi');
        $sales = $this->member($this->brandA, 'satis@example.test', role: 'Satış');
        $this->asPanel($sales)->get($this->url('/performans'))->assertForbidden();
    }
}
