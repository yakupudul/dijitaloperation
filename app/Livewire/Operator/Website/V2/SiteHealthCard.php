<?php

namespace App\Livewire\Operator\Website\V2;

use App\Models\DigitalAsset;
use App\Models\User;
use App\Services\Site\SiteHealthScore;
use App\Support\Roles;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * Site sağlığı (Aşama 7): the 0-100 score with the sector's median and leader, the four layers, every problem type
 * with one "Hepsini onar" (Admin; the type's writable rows go through the desk's approval and show on Akış) and the
 * sentence of what last month's fixes brought.
 */
final class SiteHealthCard extends Component
{
    #[Locked]
    public int $assetId = 0;

    public string $message = '';

    public function mount(int $assetId): void
    {
        $this->assetId = $assetId;
    }

    public function fixAll(string $key, SiteHealthScore $health): void
    {
        $user = auth()->user();
        abort_unless($user instanceof User && $user->hasRole(Roles::ADMIN), 403);
        $result = $health->fixAll(DigitalAsset::query()->findOrFail($this->assetId), $key, $user);
        $this->message = $result['applied'] > 0 ? $result['applied'].' iş onaylandı ve siteye gönderime alındı. Akış\'ta izleyebilirsin; ertesi gün sitede doğrulanır.' : 'Bu türde gönderilecek hazır iş kalmadı.';
        $this->dispatch('operator-notice', message: $this->message, tone: 'success');
    }

    public function placeholder(): string
    {
        return '<div class="card p-5 text-sm text-gray-500">Site sağlığı okunuyor…</div>';
    }

    public function render(SiteHealthScore $health): View
    {
        $site = DigitalAsset::query()->findOrFail($this->assetId);
        $problems = $site->brand_id !== null ? $health->problems($site) : [];
        $score = $health->score($site, $problems);
        $history = $health->history($site);

        return view('livewire.operator.website.v2.site-health-card', [
            'score' => $score,
            'problems' => collect($problems)->groupBy('layer'),
            'sector' => $site->brand_id !== null ? $health->sector($site) : ['median' => null, 'leader' => null, 'sites' => 0, 'rank' => null],
            'result' => $site->brand_id !== null ? $health->result($site) : null,
            'trend' => $history->pluck('score')->map(fn ($s): int => (int) $s)->all(),
            'previous' => $history->count() > 7 ? (int) $history[$history->count() - 8]->score : null,
            'isAdmin' => (bool) auth()->user()?->hasRole(Roles::ADMIN),
            'brandless' => $site->brand_id === null,
        ]);
    }
}
