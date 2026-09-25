<?php

namespace App\Livewire\Operator\Brain;

use App\Support\Demo\DemoState;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Hizmet Beyni › Yöntemler: what the Brain learned per service — hypotheses (seen in successful pages), validated
 * methods (applied and measured against similar pages) and retired ones. An operator can switch a method off.
 */
#[Layout('operator.layouts.app')]
#[Title('Yöntemler')]
final class MethodsPage extends Component
{
    #[Url]
    public string $status = '';

    public function retire(int $id): void
    {
        abort_unless(auth()->user()?->is_active, 403);
        DB::table('brain_methods')->where('id', $id)->update(['status' => 'retired', 'updated_at' => now()]);
        DB::table('brain_recommendations')->where('method_id', $id)->where('status', 'open')->update(['status' => 'dismissed', 'resolved_by' => auth()->id(), 'resolved_at' => now(), 'updated_at' => now()]);
        DemoState::flash('Yöntem kapatıldı; açık önerileri de kapatıldı ve bir daha önerilmez.');
    }

    public function restore(int $id): void
    {
        abort_unless(auth()->user()?->is_active, 403);
        DB::table('brain_methods')->where('id', $id)->update(['status' => 'hypothesis', 'updated_at' => now()]);
        DemoState::flash('Yöntem yeniden açıldı (hipotez olarak).');
    }

    public function render(): View
    {
        $methods = DB::table('brain_methods as m')
            ->leftJoin('service_catalog_names as n', function ($join): void {
                $join->on('n.service_catalog_item_id', '=', 'm.service_id')->where('n.is_primary', true);
            })
            ->when($this->status !== '', fn ($q) => $q->where('m.status', $this->status))
            ->orderByRaw("case m.status when 'validated' then 0 when 'hypothesis' then 1 else 2 end")->orderBy('n.raw_label')->orderBy('m.page_type')
            ->get(['m.*', 'n.raw_label as service_name']);
        $applied = DB::table('brain_recommendations')->whereNotNull('method_id')->groupBy('method_id')
            ->selectRaw("method_id, sum(case when status = 'done' then 1 else 0 end) as done, sum(case when status = 'open' then 1 else 0 end) as open")->get()->keyBy('method_id');

        return view('livewire.operator.brain.methods', ['methods' => $methods, 'applied' => $applied, 'flash' => DemoState::pullFlash()]);
    }
}
