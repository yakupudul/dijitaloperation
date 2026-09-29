<?php

namespace App\Livewire\Operator\Workspace\Concerns;

use App\Models\Brand;
use App\Models\User;
use App\Services\Analyst\AnalystWorkspace;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Card buttons of the brand workspace (x-workspace.decision-card): run the action, Yapıldı, Ertele, Gereksiz, and
 * "Yeniden analiz et". The component provides the brand id and shows the result line.
 */
trait HandlesAnalystDecisions
{
    abstract protected function analystBrandId(): int;

    abstract protected function analystNotice(string $message, string $tone = 'success'): void;

    public function runDecisionAction(int $decisionId): void
    {
        $this->decisionCall(fn (AnalystWorkspace $ws, Brand $brand, User $user): string => $ws->perform($brand, $decisionId, $user));
    }

    public function markDecisionDone(int $decisionId): void
    {
        $this->decisionCall(function (AnalystWorkspace $ws, Brand $brand, User $user) use ($decisionId): string {
            $ws->done($brand, $decisionId, $user);

            return 'Yapıldı olarak işaretlendi; etkisi sonraki analizlerde ölçülür.';
        });
    }

    public function snoozeDecision(int $decisionId, int $days = 7): void
    {
        $this->decisionCall(function (AnalystWorkspace $ws, Brand $brand) use ($decisionId, $days): string {
            $ws->snooze($brand, $decisionId, $days);

            return $days.' gün ertelendi.';
        });
    }

    public function dismissDecision(int $decisionId): void
    {
        $this->decisionCall(function (AnalystWorkspace $ws, Brand $brand, User $user) use ($decisionId): string {
            $ws->dismiss($brand, $decisionId, $user);

            return 'Gereksiz olarak kapatıldı; değişmedikçe tekrar önerilmez.';
        });
    }

    public function reanalyze(string $channel): void
    {
        $this->decisionCall(function (AnalystWorkspace $ws, Brand $brand, User $user) use ($channel): string {
            $run = $ws->reanalyze($brand, $channel, $user);

            return $run->isActive() ? 'Analiz sırada; birkaç dakika içinde kartlar yenilenir.' : 'Analiz tamamlandı.';
        });
    }

    /** @param  callable(AnalystWorkspace, Brand, User): string  $call */
    private function decisionCall(callable $call): void
    {
        $user = auth()->user();
        abort_unless($user instanceof User && $user->is_active, 403);
        $brand = Brand::query()->find($this->analystBrandId());
        if ($brand === null) {
            $this->analystNotice('Marka bulunamadı.', 'error');

            return;
        }
        try {
            $this->analystNotice($call(app(AnalystWorkspace::class), $brand, $user));
        } catch (ValidationException $exception) {
            $this->analystNotice((string) (collect($exception->errors())->flatten()->first() ?? 'İşlem yapılamadı.'), 'error');
        } catch (Throwable $exception) {
            report($exception);
            $this->analystNotice('İşlem yapılamadı.', 'error');
        }
    }
}
