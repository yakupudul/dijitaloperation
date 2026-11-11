<?php

namespace App\Livewire\Operator\Marketing;

use App\Models\SalesLead;
use App\Services\Marketing\AdLeads;
use App\Services\Marketing\MarketingActions;
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
 * Pazarlama (rebuilt 2026-11-08, yakup: "durumu çok kötü, sıfırdan kurgula"): one screen like the Gelen kutusu. Left
 * the channels, each with what it cost and what it brought this month against its target; right the chosen channel's
 * whole story (money and traffic in, applications, offers, customers and ₺ out, its records, its campaigns / searches /
 * posts) or, on "Genel bakış", the few things the month asks for, the funnel and six months of applications. Records
 * whose source is unknown are marked here with one click, so the funnel counts what really happened.
 */
#[Layout('operator.layouts.app')]
#[Title('Pazarlama')]
final class MarketingPage extends Component
{
    /** Old tab links (?sekme=…) open the channel that replaced the tab. */
    private const array LEGACY_TABS = ['reklam' => 'meta', 'site' => 'web', 'sosyal' => 'social', 'huni' => ''];

    /** The selected channel; empty = Genel bakış. */
    #[Url(as: 'kanal', except: '')]
    public string $channel = '';

    #[Url(as: 'sekme', except: '')]
    public string $tab = '';

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
        if ($this->tab !== '' && $this->channel === '') {
            $this->channel = self::LEGACY_TABS[$this->tab] ?? '';
        }
        $this->tab = '';
        $this->check();
        $this->targets = $funnel->targets();
    }

    public function updated(): void
    {
        $this->check();
    }

    private function check(): void
    {
        if ($this->channel !== '' && ! isset(MarketingFunnel::CHANNELS[$this->channel])) {
            $this->channel = '';
        }
        if ($this->month !== '' && preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $this->month) !== 1) {
            $this->month = '';
        }
        if (! isset(MarketingFunnel::SPANS[$this->span])) {
            $this->span = 1;
        }
        $this->filter = app(MarketingFunnel::class)->filterKey($this->filter);
    }

    public function show(string $channel): void
    {
        $this->channel = isset(MarketingFunnel::CHANNELS[$channel]) ? $channel : '';
        $this->message = '';
    }

    public function shift(int $months): void
    {
        $months = max(-120, min(120, $months));
        $now = CarbonImmutable::now(MarketingFunnel::TZ)->format('Y-m');
        $this->month = $this->current()->addMonths($months)->format('Y-m');
        if ($this->month >= $now) {
            $this->month = '';
        }
    }

    /** One click on a record of the list: where they heard of us. */
    public function setSource(int $leadId, string $heardFrom, MarketingFunnel $funnel): void
    {
        $lead = SalesLead::query()->relevant()->find($leadId);
        if ($lead === null || ! isset(SalesLead::HEARD_FROM[$heardFrom])) {
            return;
        }
        $funnel->setHeardFrom($lead, $heardFrom, auth()->user());
        $this->message = $lead->title().': '.SalesLead::HEARD_FROM[$heardFrom].' olarak işaretlendi.';
    }

    /** Every suggestion on the unknown list at once (each read from the record's first WhatsApp message). */
    public function applySuggestions(MarketingFunnel $funnel): void
    {
        $done = 0;
        foreach ($funnel->records($this->current(), $this->filter, $this->span, 'unknown') as $record) {
            if ($record['suggestion'] !== null && ($lead = SalesLead::query()->find($record['id'])) !== null) {
                $funnel->setHeardFrom($lead, $record['suggestion']['key'], auth()->user());
                $done++;
            }
        }
        $this->message = $done > 0 ? $done.' kaydın kaynağı ilk mesajına göre işaretlendi.' : 'Uygulanacak öneri yok.';
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

    public function render(MarketingFunnel $funnel, OwnChannels $own, MarketingActions $actions): View
    {
        $this->check();
        $month = $this->current();
        $isThisMonth = $month->format('Y-m') === CarbonImmutable::now(MarketingFunnel::TZ)->format('Y-m');
        $funnelData = $funnel->funnel($month, $this->filter, $this->span);
        $pace = $funnel->pace($month, $funnelData['totals']['target']);
        $channels = ['meta' => $own->meta($month), 'google_ads' => $own->googleAds($month), 'web' => $own->site($month), 'gbp' => $own->gbp($month), 'social' => $own->social($month)];
        $unknownRecords = $funnel->records($month, $this->filter, $this->span, 'unknown');
        $suggested = count(array_filter($unknownRecords, fn (array $r): bool => $r['suggestion'] !== null));

        return view('livewire.operator.marketing.marketing-page', [
            'monthDate' => $month,
            'isThisMonth' => $isThisMonth,
            'funnel' => $funnelData,
            'rows' => collect($funnelData['rows'])->keyBy('key')->all(),
            'pace' => $pace,
            'own' => $channels,
            'actions' => $actions->build($funnelData, $pace, $channels['meta'], $channels['google_ads'], $channels['web'], $channels['gbp'], $channels['social'], $suggested, $isThisMonth),
            'trend' => $this->channel === '' ? $funnel->trend($month, $this->filter) : [],
            'summary' => $this->channel === '' ? $funnel->summary($month, $this->filter) : [],
            'records' => $this->channel === 'unknown' ? $unknownRecords : ($this->channel !== '' ? $funnel->records($month, $this->filter, $this->span, $this->channel) : []),
            'suggested' => $suggested,
            'adLeads' => $this->channel === 'meta' ? app(AdLeads::class)->rows() : [],
            'filters' => $funnel->filters(),
            'website' => $own->brand()['website'] ?? null,
            'isAdmin' => (bool) auth()->user()?->hasRole(Roles::ADMIN),
        ]);
    }

    private function current(): CarbonImmutable
    {
        return ($this->month !== '' ? CarbonImmutable::parse($this->month.'-01', MarketingFunnel::TZ) : CarbonImmutable::now(MarketingFunnel::TZ))->startOfMonth();
    }
}
