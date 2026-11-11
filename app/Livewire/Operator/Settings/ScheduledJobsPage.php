<?php

namespace App\Livewire\Operator\Settings;

use App\Services\Operations\ScheduledJobs;
use App\Support\Roles;
use Illuminate\Contracts\View\View;
use InvalidArgumentException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Ayarlar › Zamanlanmış işler (Admin, Bağlantı Düzeni 2.4): what the system starts by itself, how often, how much
 * time each took in the last 30 days, and a switch per job. A job switched off does not run until it is switched on.
 */
#[Layout('operator.layouts.app')]
#[Title('Zamanlanmış işler')]
final class ScheduledJobsPage extends Component
{
    #[Url(as: 'ara')]
    public string $search = '';

    /** hepsi | kapali | hatali */
    #[Url(as: 'goster')]
    public string $show = 'hepsi';

    public string $message = '';

    public function mount(): void
    {
        $this->authorizeAdmin();
    }

    public function setJob(string $key, bool $on, ScheduledJobs $jobs): void
    {
        $this->authorizeAdmin();
        try {
            $jobs->set($key, $on);
            $this->message = $on ? 'İş açıldı; sıradaki saatinde çalışır.' : 'İş kapatıldı; yeniden açılana kadar çalışmaz.';
        } catch (InvalidArgumentException $error) {
            $this->message = $error->getMessage();
        }
    }

    public function render(ScheduledJobs $jobs): View
    {
        $all = $jobs->all();
        $needle = mb_strtolower(trim($this->search));
        $rows = array_values(array_filter($all, fn (array $job): bool => ($needle === '' || str_contains(mb_strtolower($job['name'].' '.$job['key'].' '.$job['what']), $needle))
            && match ($this->show) {
                'kapali' => $job['off'],
                'hatali' => $job['failures'] > 0,
                default => true,
            }));

        return view('livewire.operator.settings.scheduled-jobs', [
            'rows' => $rows,
            'total' => count($all),
            'offCount' => count(array_filter($all, fn (array $job): bool => $job['off'])),
            'failing' => count(array_filter($all, fn (array $job): bool => $job['failures'] > 0)),
            'minutes' => (int) round(array_sum(array_column($all, 'minutes'))),
        ]);
    }

    private function authorizeAdmin(): void
    {
        abort_unless(auth()->user()?->is_active && auth()->user()?->hasRole(Roles::ADMIN), 403);
    }
}
