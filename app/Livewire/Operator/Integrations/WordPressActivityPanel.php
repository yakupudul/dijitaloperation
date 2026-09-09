<?php

namespace App\Livewire\Operator\Integrations;

use App\Models\CoreConnection;
use App\Models\DigitalAsset;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\WithPagination;

final class WordPressActivityPanel extends Component
{
    use WithPagination;

    #[Locked]
    public int $assetId;

    public string $from = '';
    public string $until = '';
    public string $kind = '';
    public string $actor = '';

    public function mount(int $assetId): void
    {
        DigitalAsset::query()->where('type', 'website')->findOrFail($assetId);
        $this->assetId = $assetId;
        $this->from = now()->subDays(30)->toDateString();
        $this->until = now()->toDateString();
    }

    public function updated(string $property): void
    {
        if (in_array($property, ['from', 'until', 'kind', 'actor'], true)) {
            $this->resetPage('wpActivity');
        }
    }

    public function render(): View
    {
        $query = DB::table('website_connector_events')->where('digital_asset_id', $this->assetId);
        foreach (['from' => '>=', 'until' => '<='] as $field => $operator) {
            if (preg_match('/^\\d{4}-\\d{2}-\\d{2}$/', $this->$field)) {
                $query->where('occurred_at', $operator, $this->$field.($field === 'from' ? ' 00:00:00' : ' 23:59:59'));
            }
        }
        if (in_array($this->kind, ['content', 'seo', 'settings', 'maintenance', 'access'], true)) {
            $query->where('type', 'like', $this->kind.'.%');
        }
        if ($this->actor !== '') {
            $query->where('actor_name', $this->actor);
        }
        $counts = (clone $query)->selectRaw('type, COUNT(*) AS total')->groupBy('type')->pluck('total', 'type');
        $connection = CoreConnection::query()->where('digital_asset_id', $this->assetId)->where('type', 'wordpress_connector')->first();
        $delivery = $connection ? DB::table('website_connector_delivery')->where('connection_id', $connection->id)->first() : null;
        return view('livewire.operator.integrations.wordpress-activity-panel', [
            'events' => $query->orderByDesc('occurred_at')->orderByDesc('id')->paginate(25, ['*'], 'wpActivity'),
            'counts' => $counts,
            'delivery' => $delivery,
            'actors' => DB::table('website_connector_events')->where('digital_asset_id', $this->assetId)
                ->whereNotNull('actor_name')->where('actor_name', '!=', '')->distinct()->orderBy('actor_name')->limit(100)->pluck('actor_name'),
        ]);
    }
}
