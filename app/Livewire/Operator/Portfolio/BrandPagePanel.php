<?php

namespace App\Livewire\Operator\Portfolio;

use App\Jobs\ApproveKomutaGroupJob;
use App\Models\Brand;
use App\Services\Marketing\MarketingFunnel;
use App\Services\Operator\BrandOverviewReader;
use App\Services\Operator\BrandPageReader;
use App\Support\Demo\DemoMenu;
use App\Support\Roles;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Cache;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Throwable;

/**
 * Marka sayfası (Aşama 6): the month's goal and the four channel cards (BrandPageReader). Loaded after the page: the
 * desk read behind the findings is the heavy part. "Hepsini çöz" only queues the channel's ready work (the same job as
 * Komuta's "Hepsini onayla"), so a big brand never times out the click.
 */
final class BrandPagePanel extends Component
{
    #[Locked]
    public int $brandId;

    #[Locked]
    public int $days = 28;

    public string $message = '';

    public function mount(int $brandId, int $days = 28): void
    {
        $this->brandId = $brandId;
        $this->days = $days;
    }

    public function placeholder(): string
    {
        return '<div class="grid gap-4 md:grid-cols-2" aria-busy="true"><div class="card h-40 animate-pulse"></div><div class="card h-40 animate-pulse"></div></div>';
    }

    public function solve(string $channel): void
    {
        $user = auth()->user();
        abort_unless($user !== null && $user->hasRole(Roles::ADMIN), 403);
        abort_unless(isset(BrandPageReader::CARDS[$channel]), 404);
        $key = ApproveKomutaGroupJob::brandKey($this->brandId, $channel);
        if (! Cache::add(ApproveKomutaGroupJob::lockKey($key), $user->id, now()->addMinutes(15))) {
            $this->message = 'Bu kanalın işleri zaten sırada; birkaç dakika içinde Akış\'ta görünür.';

            return;
        }
        ApproveKomutaGroupJob::dispatch($key, (int) $user->id)->onQueue((string) config('queue.writes_queue', 'default'));
        $this->message = BrandPageReader::CARDS[$channel][0].' işleri onaya alındı; birkaç dakika içinde gönderilmeye başlar.';
    }

    public function render(BrandPageReader $reader, BrandOverviewReader $overview): View
    {
        $brand = Brand::query()->findOrFail($this->brandId);
        $goal = null;
        $kpis = [];
        $cards = [];
        try {
            $models = $overview->assetModels($brand);
            $kpis = $overview->kpis($brand, $models, $overview->assetCards($models), $this->days);
            $goal = $reader->goal($brand);
            $cards = $reader->cards($brand, $kpis);
        } catch (Throwable $error) {
            report($error);
        }
        // Pazarlama Düzeni 1.1: the agency's own brand has one target counter, the one on Pazarlama (applications from the
        // sales records); what the site and profiles measured (clicks, calls) is shown under it as measured touches.
        $own = null;
        try {
            if ((int) (DemoMenu::agencyBrand()['id'] ?? 0) === (int) $brand->id) {
                $funnel = app(MarketingFunnel::class);
                $month = CarbonImmutable::now(MarketingFunnel::TZ)->startOfMonth();
                $totals = $funnel->funnel($month)['totals'];
                $own = ['counted' => (int) $totals['counted'], 'target' => (int) $totals['target'], 'expected' => $funnel->pace($month, (int) $totals['target'])['expected'],
                    'measured' => (int) round((float) ($goal['goal']['mtd'] ?? 0))];
            }
        } catch (Throwable $error) {
            report($error);
        }

        return view('livewire.operator.portfolio.brand-page-panel', ['brand' => $brand, 'goal' => $goal, 'kpis' => $kpis, 'cards' => $cards, 'own' => $own,
            'isAdmin' => (bool) auth()->user()?->hasRole(Roles::ADMIN)]);
    }
}
