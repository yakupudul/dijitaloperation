<?php

namespace App\Livewire\Operator;

use App\Jobs\EraseSourceDataJob;
use App\Services\DataCenter\DataCenterCatalog;
use App\Services\DataCenter\DataCenterReader;
use App\Support\Demo\DemoState;
use App\Support\Roles;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Veri merkezi: which source (account or website) data was collected from, what is stored for it and which brand it
 * feeds. Deleting a customer or brand keeps its data; here the admin can pick data sets of a source and delete them.
 * Queries, search terms and keywords are protected and cannot be deleted.
 */
#[Layout('operator.layouts.app')]
#[Title('Veri merkezi')]
final class DataCenterPage extends Component
{
    #[Url]
    public string $provider = '';

    #[Url]
    public string $q = '';

    #[Url]
    public bool $unbound = false;

    public ?string $open = null;

    /** @var array<string, list<string>> source key => selected data sets */
    public array $picked = [];

    public function toggle(string $key): void
    {
        $this->open = $this->open === $key ? null : $key;
    }

    public function pickAll(string $key): void
    {
        $source = collect(app(DataCenterReader::class)->sources())->firstWhere('key', $key);
        $this->picked[$key] = $source === null ? [] : collect($source['datasets'])->where('protected', false)->pluck('dataset')->values()->all();
    }

    public function erase(string $key, DataCenterCatalog $catalog): void
    {
        abort_unless(auth()->user()?->hasRole(Roles::ADMIN), 403);
        [$kind, $id] = explode(':', $key) + [null, null];
        abort_unless(in_array($kind, ['resource', 'asset'], true) && is_numeric($id), 422);
        $datasets = array_values(array_filter((array) ($this->picked[$key] ?? []), fn ($d): bool => is_string($d) && ! $catalog->isProtected($d)));
        if ($datasets === []) {
            DemoState::flash('Silinecek veri seti seçilmedi.');

            return;
        }
        EraseSourceDataJob::dispatch((string) $kind, (int) $id, $datasets, auth()->id());
        unset($this->picked[$key]);
        DemoState::flash(count($datasets).' veri seti arka planda siliniyor. Sorgu ve arama terimleri korunur.');
    }

    public function render(DataCenterReader $reader): View
    {
        $sources = collect($reader->sources());
        $providers = $sources->pluck('provider')->unique()->sort()->values()->all();
        $needle = mb_strtolower(trim($this->q));
        $visible = $sources
            ->when($this->provider !== '', fn ($c) => $c->where('provider', $this->provider))
            ->when($this->unbound, fn ($c) => $c->where('bound', false))
            ->when($needle !== '', fn ($c) => $c->filter(fn (array $s): bool => str_contains(mb_strtolower($s['name'].' '.implode(' ', $s['feeds'])), $needle)))
            ->values();

        return view('livewire.operator.data-center', [
            'sources' => $visible,
            'providers' => $providers,
            'totals' => ['sources' => $sources->count(), 'unbound' => $sources->where('bound', false)->count(), 'rows' => $sources->sum('rows')],
            'isAdmin' => (bool) auth()->user()?->hasRole(Roles::ADMIN),
        ]);
    }
}
