<?php

namespace App\Livewire\Operator\Repair;

use App\Services\Work\DailySend;
use App\Support\Roles;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Cache;
use Livewire\Component;

/**
 * Günlük gönderim (yakup, 2026-10-14, "Günde 1 tık"): the top strip of the Onarım masası. Shows what the night prepared
 * and AI checked (articles, Business Profile posts, desk fixes) and what the check held back with its reason; one Admin
 * button sends everything that passed (SendDailyBatchJob, off the web request).
 */
final class DailySendStrip extends Component
{
    public string $message = '';

    public bool $showHeld = false;

    public function send(DailySend $daily): void
    {
        abort_unless(auth()->user()?->hasRole(Roles::ADMIN), 403);
        if (Cache::has(DailySend::RUNNING_KEY)) {
            $this->message = 'Gönderim zaten sürüyor.';

            return;
        }
        $daily->queue(auth()->user());
        $this->message = 'Gönderim başladı; birkaç dakika içinde biter. Yazılar WordPress\'e tarihli taslak olarak, gönderiler gününde, onarımlar "Yapılanlar"a gider.';
    }

    public function placeholder(): string
    {
        return '<div class="rounded-xl bg-white p-4 text-sm text-gray-500 ring-1 ring-inset ring-gray-200 dark:bg-gray-900 dark:ring-gray-800">Günlük gönderim hazırlanıyor…</div>';
    }

    public function render(DailySend $daily): View
    {
        return view('livewire.operator.repair.daily-send-strip', [
            'summary' => $daily->summary(),
            'running' => Cache::has(DailySend::RUNNING_KEY),
            'last' => Cache::get(DailySend::LAST_KEY),
            'isAdmin' => (bool) auth()->user()?->hasRole(Roles::ADMIN),
        ]);
    }
}
