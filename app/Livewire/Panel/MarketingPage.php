<?php

namespace App\Livewire\Panel;

use App\Models\BrandSession;
use App\Panel\Models\PanelTicket;
use App\Panel\Services\PanelLeads;
use App\Panel\Services\PanelMarketing;
use App\Panel\Services\PanelPerformance;
use App\Panel\Services\PanelSupport;
use App\Panel\Support\PanelPage;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/** Pazarlama: the month's report, what the agency did, what will be published and the accounts it works on. */
#[Layout('panel.layouts.app')]
#[Title('Pazarlama')]
final class MarketingPage extends Component
{
    use PanelPage;

    public const array TABS = ['rapor', 'yaptiklarimiz', 'yayinlanacaklar', 'varliklar'];

    public string $tab = 'rapor';

    #[Url(as: 'ay')]
    public string $month = '';

    /** The upcoming item a change is being asked for, and what should change. */
    public ?string $changing = null;

    public string $change = '';

    protected function permission(): string
    {
        return 'menu.marketing';
    }

    public function mount(string $tab = 'rapor'): void
    {
        $this->tab = in_array($tab, self::TABS, true) ? $tab : 'rapor';
    }

    /** The client does not approve content; it only says what should change, as a request under Destek. */
    public function askChange(PanelMarketing $marketing, PanelSupport $support): void
    {
        abort_unless($this->may('support.write'), 403);
        $item = $marketing->upcoming((int) $this->panel()->brandId())->firstWhere('key', (string) $this->changing);
        abort_if($item === null, 404);
        $this->validate(['change' => ['required', 'string', 'max:2000']], ['change.required' => 'Neyin değişmesini istediğinizi yazın.'], ['change' => 'istek']);
        $subject = 'Değişiklik isteği: '.$item['on']->locale('tr')->isoFormat('D MMMM').' günkü '.mb_strtolower($item['kind']);
        $support->open((int) $this->panel()->brandId(), (int) $this->panelUser()->id, mb_substr($subject, 0, 190), 'wish', trim($this->change)."\n\nGönderi: ".mb_substr($item['text'], 0, 600));
        $this->changing = null;
        $this->change = '';
        $this->notify('İsteğiniz Moximu\'ya ulaştı. Gönderi yayınlanmadan önce bakıyoruz.');
    }

    public function render(PanelMarketing $marketing, PanelLeads $leads, PanelPerformance $performance): View
    {
        $brandId = (int) $this->panel()->brandId();
        $data = ['reports' => collect(), 'report' => null, 'sales' => null, 'work' => null, 'upcoming' => collect(), 'asked' => collect(), 'assets' => []];
        if ($this->tab === 'rapor') {
            $data['reports'] = $marketing->reports($brandId);
            /** @var ?BrandSession $report */
            $report = $data['reports']->firstWhere('month', $this->month) ?? $data['reports']->first();
            if ($report !== null) {
                $marketing->markRead($report);
                $from = CarbonImmutable::parse($report->month.'-01', 'Europe/Istanbul')->startOfMonth();
                $data['sales'] = $this->may('menu.performance') ? $performance->sales($leads->visible(), $from->utc(), $from->addMonth()->utc()) : null;
            }
            $data['report'] = $report;
        } elseif ($this->tab === 'yaptiklarimiz') {
            $data['work'] = $marketing->work($brandId);
        } elseif ($this->tab === 'yayinlanacaklar') {
            $data['upcoming'] = $marketing->upcoming($brandId);
            $data['asked'] = PanelTicket::query()->where('subject', 'like', 'Değişiklik isteği:%')->where('status', '!=', PanelTicket::SOLVED)->pluck('subject');
        } else {
            $data['assets'] = $marketing->assets($brandId);
        }

        return view('livewire.panel.marketing', $data + ['canAsk' => $this->may('support.write')]);
    }
}
