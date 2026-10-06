<?php

namespace App\Livewire\Operator;

use App\Jobs\EraseSourceDataJob;
use App\Services\DataCenter\DataCenterCatalog;
use App\Services\DataCenter\DataCenterReader;
use App\Support\Demo\DemoState;
use App\Support\Roles;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Computed;
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
        // A checkbox bound to an unset key is a boolean in Livewire (true / false), not a list — start it as a list.
        if ($this->open !== null && ! is_array($this->picked[$key] ?? null)) {
            $this->picked[$key] = [];
        }
    }

    /** Livewire hook: keep every picked.* entry a list of data set names (never true / false / a single string). */
    public function updatedPicked(mixed $value, ?string $key = null): void
    {
        foreach ($this->picked as $source => $selected) {
            $this->picked[$source] = self::selection($selected);
        }
    }

    /**
     * @return list<string>
     */
    public static function selection(mixed $selected): array
    {
        return is_array($selected) ? array_values(array_filter($selected, fn ($d): bool => is_string($d) && $d !== '')) : [];
    }

    /**
     * Every source with its stored data sets (row counts from the cache), read once per request: an action and the
     * render after it share it.
     *
     * @return list<array<string, mixed>>
     */
    #[Computed]
    public function allSources(): array
    {
        return app(DataCenterReader::class)->sources();
    }

    public function pickAll(string $key): void
    {
        $source = collect($this->allSources)->firstWhere('key', $key);
        $this->picked[$key] = $source === null ? [] : collect($source['datasets'])->where('protected', false)->pluck('dataset')->values()->all();
    }

    public function erase(string $key, DataCenterCatalog $catalog): void
    {
        abort_unless(auth()->user()?->hasRole(Roles::ADMIN), 403);
        [$kind, $id] = explode(':', $key) + [null, null];
        abort_unless(in_array($kind, ['resource', 'asset'], true) && is_numeric($id), 422);
        $datasets = array_values(array_filter(self::selection($this->picked[$key] ?? []), fn (string $d): bool => ! $catalog->isProtected($d)));
        if ($datasets === []) {
            DemoState::flash('Silinecek veri seti seçilmedi.');

            return;
        }
        EraseSourceDataJob::dispatch((string) $kind, (int) $id, $datasets, auth()->id());
        unset($this->picked[$key]);
        DemoState::flash(count($datasets).' veri seti arka planda siliniyor. Sorgu ve arama terimleri korunur.');
    }

    public function render(): View
    {
        $sources = collect($this->allSources);
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
