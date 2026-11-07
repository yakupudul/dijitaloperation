<?php

namespace App\Livewire\Operator\Marketing;

use App\Services\Marketing\MarketingFunnel;
use App\Services\Marketing\OwnChannels;
use App\Support\Roles;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Pazarlama (Moximu büyüme sistemi, Aşama 4; yakup 2026-11-07): which source brings how many applications, offers,
 * customers and ₺. On top the one funnel against the 25-a-month target channel by channel, filtered by Tür (default
 * health); the tabs are our own channels: social media, our ads and the site. The social plan itself stays on its
 * own screen (Sosyal medya), linked from here.
 */
#[Layout('operator.layouts.app')]
#[Title('Pazarlama')]
final class MarketingPage extends Component
{
    public const array TABS = ['huni' => 'Huni', 'sosyal' => 'Sosyal medya', 'reklam' => 'Reklamlarımız', 'site' => 'Site'];

    #[Url(as: 'sekme', except: 'huni')]
    public string $tab = 'huni';

    /** The month shown (Y-m); empty = this month. */
    #[Url(as: 'ay', except: '')]
    public string $month = '';

    #[Url(as: 'tur', except: 'saglik')]
    public string $filter = 'saglik';

    #[Url(as: 'donem', except: 1)]
    public int $span = 1;

    public bool $editingTargets = false;

    /** @var array<string, int|string> */
    public array $targets = [];

    public string $message = '';

    public function mount(MarketingFunnel $funnel): void
    {
        $this->check();
        $this->targets = $funnel->targets();
    }

    public function updated(): void
    {
        $this->check();
    }

    private function check(): void
    {
        if (! isset(self::TABS[$this->tab])) {
            $this->tab = 'huni';
        }
        if ($this->month !== '' && preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $this->month) !== 1) {
            $this->month = '';
        }
        if (! isset(MarketingFunnel::SPANS[$this->span])) {
            $this->span = 1;
        }
        $this->filter = app(MarketingFunnel::class)->filterKey($this->filter);
    }

    public function shift(int $months): void
    {
        $months = max(-120, min(120, $months));
        $this->month = $this->current()->addMonths($months)->format('Y-m');
        if ($this->month > CarbonImmutable::now(MarketingFunnel::TZ)->format('Y-m')) {
            $this->month = '';
        }
        if ($this->month === CarbonImmutable::now(MarketingFunnel::TZ)->format('Y-m')) {
            $this->month = '';
        }
    }

    public function editTargets(): void
    {
        abort_unless((bool) auth()->user()?->hasRole(Roles::ADMIN), 403);
        $this->targets = app(MarketingFunnel::class)->targets();
        $this->editingTargets = true;
    }

    public function saveTargets(MarketingFunnel $funnel): void
    {
        abort_unless((bool) auth()->user()?->hasRole(Roles::ADMIN), 403);
        $funnel->saveTargets($this->targets);
        $this->targets = $funnel->targets();
        $this->editingTargets = false;
        $this->message = 'Hedef kaydedildi: ayda '.array_sum($this->targets).' başvuru.';
    }

    public function render(MarketingFunnel $funnel, OwnChannels $own): View
    {
        $this->check();
        $month = $this->current();
        $funnelData = $funnel->funnel($month, $this->filter, $this->span);
        $isThisMonth = $month->format('Y-m') === CarbonImmutable::now(MarketingFunnel::TZ)->format('Y-m');

        return view('livewire.operator.marketing.marketing-page', [
            'monthDate' => $month,
            'isThisMonth' => $isThisMonth,
            'funnel' => $funnelData,
            'summary' => $this->tab === 'huni' ? $funnel->summary($month, $this->filter) : [],
            'filters' => $funnel->filters(),
            'site' => $this->tab === 'site' ? $own->site($month) : null,
            'ads' => $this->tab === 'reklam' ? $own->ads($month) : null,
            'social' => $this->tab === 'sosyal' ? $own->social($month) : null,
            'isAdmin' => (bool) auth()->user()?->hasRole(Roles::ADMIN),
        ]);
    }

    private function current(): CarbonImmutable
    {
        return ($this->month !== '' ? CarbonImmutable::parse($this->month.'-01', MarketingFunnel::TZ) : CarbonImmutable::now(MarketingFunnel::TZ))->startOfMonth();
    }
}
