<?php

namespace App\Livewire\Operator\Komuta;

use App\Models\BrandTarget;
use App\Models\Suggestion;
use App\Models\User;
use App\Services\Gbp\GbpSuggestions;
use App\Services\Goals\BrandTargets;
use App\Services\Operator\KomutaReader;
use App\Services\Operator\TodayCockpitReader;
use App\Services\Repair\RepairDesk;
use App\Services\Work\ContentWeekPlan;
use App\Support\Roles;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;
use Throwable;

/**
 * Komuta (Aşama 5; prototype approved by yakup 2026-10-22): the home screen. Only what waits on the operator, from every
 * brand, in one decision box: the daily send on top, then the groups (most expected demand first), a package or a goal
 * approved in one click, "Gerek yok" for the rest. Keys: J / K move, Enter opens, A approves, X drops, G sends the day.
 * The old Bugün (week numbers, alarms, brand cards) stays at /bugun.
 */
#[Layout('operator.layouts.app')]
#[Title('Komuta')]
final class KomutaPage extends Component
{
    public string $message = '';

    /** @var array<int, string> target id => number typed for the goal */
    public array $goals = [];

    public function approveItem(string $key, KomutaReader $komuta, RepairDesk $desk, BrandTargets $targets): void
    {
        $user = $this->admin();
        if (str_starts_with($key, 'target:')) {
            $this->approveTarget((int) substr($key, 7), $targets);

            return;
        }
        if (str_starts_with($key, 'plan:')) {
            $this->approvePlan((int) substr($key, 5), $user);

            return;
        }
        if (str_starts_with($key, 'gbpname:')) {
            $rows = KomutaReader::nameRows((int) substr($key, 8));
            $rows->each(fn ($s) => app(GbpSuggestions::class)->approve($s, $user));
            $this->say($rows->count().' profilin adı düzeltilecek olarak işaretlendi; Google\'da adı tabeladaki hale getirince profil kontrolü kartı kapatır.');

            return;
        }
        $ids = $komuta->packageIds($key);
        $result = $ids === [] ? ['applied' => 0] : $desk->queueApproval($ids, $user);
        $this->say($result['applied'] > 0 ? $result['applied'].' iş onaylandı ve gönderime alındı. Akış\'ta izleyebilirsin.' : 'Bu paket artık masada değil.');
    }

    public function dismissItem(string $key, KomutaReader $komuta, RepairDesk $desk): void
    {
        $user = $this->admin();
        if (str_starts_with($key, 'gbpname:')) {
            $rows = KomutaReader::nameRows((int) substr($key, 8));
            $rows->each(fn ($s) => $s->forceFill(['status' => Suggestion::DISMISSED, 'resolved_by' => $user->id, 'resolved_at' => now(), 'operator_note' => 'Tabelada bu ad yazıyor.'])->save());
            $this->say($rows->count().' profilin adı olduğu gibi kalıyor; aynı ad yeniden sorulmaz.');

            return;
        }
        if (! str_starts_with($key, 'pkg:')) {
            return;
        }
        $ids = $komuta->packageIds($key);
        $count = $ids === [] ? 0 : $desk->reject($ids, $user, 'Komuta: gerek yok');
        $this->say($count > 0 ? $count.' iş kaldırıldı; kanıt değişmedikçe yeniden önerilmez.' : 'Bu paket artık masada değil.');
    }

    public function approveGroup(string $key, KomutaReader $komuta, RepairDesk $desk, BrandTargets $targets): void
    {
        $user = $this->admin();
        if ($key === 'targets') {
            $done = 0;
            foreach ($targets->waiting() as $target) {
                $targets->approve($target, $user);
                $done++;
            }
            $this->say($done.' markanın amacı onaylandı.');

            return;
        }
        if ($key === 'content-plan') {
            $this->approvePlan(null, $user);

            return;
        }
        $group = collect($komuta->box()['groups'])->firstWhere('key', $key);
        $ids = collect($group['items'] ?? [])->where('approve', true)->flatMap(fn (array $item): array => $komuta->packageIds($item['key']))->unique()->values()->all();
        $result = $ids === [] ? ['applied' => 0] : $desk->queueApproval($ids, $user);
        $this->say($result['applied'] > 0 ? $result['applied'].' iş onaylandı ve gönderime alındı. Akış\'ta izleyebilirsin.' : 'Bu grupta tek tıkla onaylanacak iş kalmadı.');
    }

    private function approvePlan(?int $brandId, User $user): void
    {
        try {
            $this->say(app(ContentWeekPlan::class)->approve(null, $user, $brandId));
        } catch (ValidationException $error) {
            $this->say((string) collect($error->errors())->flatten()->first());
        }
    }

    public function approveTarget(int $id, BrandTargets $targets): void
    {
        $user = $this->admin();
        $target = BrandTarget::query()->findOrFail($id);
        $typed = trim((string) ($this->goals[$id] ?? ''));
        try {
            $targets->approve($target, $user, $typed !== '' && ctype_digit($typed) && (int) $typed !== $target->proposed_leads ? (int) $typed : null);
        } catch (ValidationException $error) {
            $this->say((string) collect($error->errors())->flatten()->first());

            return;
        }
        unset($this->goals[$id]);
        $this->say($target->brand?->name.' için amaç onaylandı: ayda '.$target->fresh()->monthly_leads.' talep.');
    }

    public function render(KomutaReader $komuta, TodayCockpitReader $cockpit): View
    {
        $box = $komuta->box();
        $alarms = ['loss' => 0, 'items' => []];
        try {
            $alarms = $cockpit->alarms();
        } catch (Throwable $error) {
            report($error);
        }
        $portfolio = $this->portfolio();

        return view('livewire.operator.komuta.komuta-page', [
            'box' => $box,
            'elsewhere' => $komuta->elsewhere(),
            'loss' => collect($alarms['items'])->where('tone', 'critical')->values(),
            'portfolio' => $portfolio,
            'isAdmin' => (bool) auth()->user()?->hasRole(Roles::ADMIN),
        ]);
    }

    /** @return array{ahead: int, on_track: int, behind: int, no_goal: int} brands per goal state this month */
    private function portfolio(): array
    {
        $out = ['ahead' => 0, 'on_track' => 0, 'behind' => 0, 'no_goal' => 0];
        try {
            foreach (BrandTarget::query()->whereHas('brand', fn ($q) => $q->operational())->get() as $target) {
                $out[BrandTargets::progress($target)['state']]++;
            }
        } catch (Throwable $error) {
            report($error);
        }

        return $out;
    }

    private function say(string $message): void
    {
        $this->message = $message;
        $this->dispatch('operator-notice', message: $message, tone: 'success');
        $this->dispatch('ai-live-refresh');
    }

    private function admin(): User
    {
        $user = auth()->user();
        abort_unless($user instanceof User && $user->hasRole(Roles::ADMIN), 403);

        return $user;
    }
}
