<?php

namespace App\Livewire\Operator\Settings;

use App\Models\ScreenCheck;
use App\Models\SystemChange;
use App\Services\Operations\ReleaseInfo;
use App\Services\Operations\SystemChangeDesk;
use App\Support\Roles;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Ayarlar › Geliştirme havuzu (Admin): what Claude found in MoxDOP itself (collection, page and software errors,
 * design) waits here for approval; approved ones Claude codes and pushes; their deploy commands appear under "Deploy
 * bekliyor" with "Deploy tamamlandı"; Claude then checks the live system and closes them. The operator can also write
 * a request (approved at once).
 */
#[Layout('operator.layouts.app')]
#[Title('Geliştirme havuzu')]
final class ImprovementsPage extends Component
{
    /** Tab => [label, statuses]. */
    public const array TABS = [
        'onay' => ['Onay bekliyor', [SystemChange::PROPOSED]],
        'claude' => ['Claude yapıyor', [SystemChange::APPROVED, SystemChange::IN_PROGRESS]],
        'deploy' => ['Deploy bekliyor', [SystemChange::READY]],
        'kontrol' => ['Kontrol', [SystemChange::DEPLOYED, SystemChange::FAILED]],
        'biten' => ['Biten', [SystemChange::VERIFIED, SystemChange::REJECTED]],
    ];

    #[Url(as: 'durum')]
    public string $tab = 'onay';

    /** @var array<int|string, string> change id => operator note */
    public array $notes = [];

    public string $newKind = 'improvement';

    public string $newTitle = '';

    public string $newDetail = '';

    public bool $writing = false;

    public string $message = '';

    public function mount(): void
    {
        $this->authorizeAdmin();
        if (! isset(self::TABS[$this->tab])) {
            $this->tab = 'onay';
        }
    }

    public function setTab(string $tab): void
    {
        $this->tab = isset(self::TABS[$tab]) ? $tab : 'onay';
    }

    public function approve(int $id, SystemChangeDesk $desk): void
    {
        $this->decide($id, fn (SystemChange $change) => $desk->approve($change, auth()->user(), $this->notes[$id] ?? null), 'Onaylandı; Claude sıradaki turda yapacak.');
    }

    public function reject(int $id, SystemChangeDesk $desk): void
    {
        $this->decide($id, fn (SystemChange $change) => $desk->reject($change, auth()->user(), $this->notes[$id] ?? null), 'Reddedildi; aynı bulgu tekrar önerilmez.');
    }

    public function deployed(string $commit, SystemChangeDesk $desk): void
    {
        $this->authorizeAdmin();
        $marked = $desk->deployed($commit);
        $this->message = $marked > 0 ? $marked.' değişiklik deploy edildi olarak işaretlendi; sayfalar yeniden taranıyor, Claude kontrol edecek.' : 'Bu commit için bekleyen değişiklik yok.';
        $this->tab = 'kontrol';
    }

    public function saveRequest(SystemChangeDesk $desk): void
    {
        $this->authorizeAdmin();
        $this->validate([
            'newKind' => ['required', 'in:'.implode(',', SystemChange::KINDS)],
            'newTitle' => ['required', 'string', 'min:5', 'max:200'],
            'newDetail' => ['required', 'string', 'min:10', 'max:4000'],
        ], [], ['newTitle' => 'başlık', 'newDetail' => 'ayrıntı']);
        $desk->request($this->newKind, $this->newTitle, $this->newDetail, auth()->user());
        $this->reset('newTitle', 'newDetail', 'writing');
        $this->message = 'İstek eklendi; Claude sıradaki turda yapacak.';
        $this->tab = 'claude';
    }

    /** @param  callable(SystemChange): void  $step */
    private function decide(int $id, callable $step, string $done): void
    {
        $this->authorizeAdmin();
        $change = SystemChange::query()->findOrFail($id);
        try {
            $step($change);
            $this->message = '#'.$id.' · '.$done;
            unset($this->notes[$id]);
        } catch (ValidationException $exception) {
            $this->message = (string) collect($exception->errors())->flatten()->first();
        }
    }

    public function render(SystemChangeDesk $desk): View
    {
        $desk->markLiveReleases();
        $counts = $desk->counts();
        $changes = SystemChange::query()->whereIn('status', self::TABS[$this->tab][1])
            ->orderBy($this->tab === 'biten' ? 'updated_at' : 'priority', $this->tab === 'biten' ? 'desc' : 'asc')->orderByDesc('id')->limit(200)->get();
        $screens = ScreenCheck::query()->get();

        return view('livewire.operator.settings.improvements', [
            'changes' => $changes,
            'groups' => $this->tab === 'deploy' ? $changes->groupBy('commit_sha') : collect(),
            'tabCounts' => collect(self::TABS)->map(fn (array $tab): int => collect($tab[1])->sum(fn (string $status): int => (int) ($counts[$status] ?? 0)))->all(),
            'release' => ReleaseInfo::current(),
            'screens' => ['total' => $screens->count(), 'failed' => $screens->filter(fn (ScreenCheck $check): bool => $check->failed())->count(),
                'checked_at' => $screens->max('checked_at')],
        ]);
    }

    private function authorizeAdmin(): void
    {
        abort_unless(auth()->user()?->is_active && auth()->user()?->hasRole(Roles::ADMIN), 403);
    }
}
