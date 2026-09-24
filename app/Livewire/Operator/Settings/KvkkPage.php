<?php

namespace App\Livewire\Operator\Settings;

use App\Enums\CustomerStatus;
use App\Models\AgencySetting;
use App\Models\Customer;
use App\Support\Roles;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Ayarlar › KVKK (Faz 10d): per active customer the data processing agreement date and note, whether health data
 * is processed; the WhatsApp message text retention period. Admin edits.
 */
#[Layout('operator.layouts.app')]
#[Title('KVKK')]
final class KvkkPage extends Component
{
    /** @var array<int, array{signed_on: string, note: string, health: bool}> */
    public array $rows = [];

    public string $retention = '';

    public string $message = '';

    public function mount(): void
    {
        abort_unless(auth()->user()?->hasRole(Roles::ADMIN), 403);
        foreach (Customer::query()->where('status', CustomerStatus::Active->value)->orderBy('name')->get() as $customer) {
            $this->rows[$customer->id] = ['signed_on' => (string) ($customer->kvkk_dpa_signed_on ? substr((string) $customer->kvkk_dpa_signed_on, 0, 10) : ''), 'note' => (string) $customer->kvkk_dpa_note, 'health' => (bool) $customer->kvkk_health_data];
        }
        $days = AgencySetting::query()->value('whatsapp_retention_days');
        $this->retention = $days !== null ? (string) $days : '';
    }

    public function save(): void
    {
        abort_unless(auth()->user()?->hasRole(Roles::ADMIN), 403);
        $this->validate([
            'rows.*.signed_on' => ['nullable', 'date'],
            'rows.*.note' => ['nullable', 'string', 'max:300'],
            'retention' => ['nullable', 'integer', 'min:30', 'max:3650'],
        ]);
        foreach ($this->rows as $id => $row) {
            DB::table('customers')->where('id', (int) $id)->update([
                'kvkk_dpa_signed_on' => filled($row['signed_on']) ? $row['signed_on'] : null,
                'kvkk_dpa_note' => filled($row['note']) ? mb_substr((string) $row['note'], 0, 300) : null,
                'kvkk_health_data' => (bool) ($row['health'] ?? false),
                'updated_at' => now(),
            ]);
        }
        AgencySetting::query()->firstOrCreate([])->forceFill(['whatsapp_retention_days' => filled($this->retention) ? (int) $this->retention : null])->save();
        $this->message = 'Kaydedildi.';
    }

    public function render(): View
    {
        return view('livewire.operator.settings.kvkk', [
            'customers' => Customer::query()->where('status', CustomerStatus::Active->value)->orderBy('name')->get(['id', 'name']),
            'missing' => collect($this->rows)->filter(fn (array $r): bool => blank($r['signed_on']))->count(),
        ]);
    }
}
