<?php

namespace App\Livewire\Operator\Reports;

use App\Enums\CustomerStatus;
use App\Models\Brand;
use App\Services\MonthlyReport\ChartAnnotations;
use App\Support\Roles;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Raporlar › Grafik notları (Faz 10a): Google algorithm updates and regulation changes for all brands, and
 * campaigns / site changes per brand. They appear on report charts and in the report's events list.
 */
#[Layout('operator.layouts.app')]
#[Title('Grafik notları')]
final class ChartAnnotationsPage extends Component
{
    #[Url]
    public string $filter = 'all';

    /** @var array{brand_id: string, kind: string, starts_on: string, ends_on: string, title: string, note: string, source_url: string} */
    public array $form = ['brand_id' => '', 'kind' => 'algorithm', 'starts_on' => '', 'ends_on' => '', 'title' => '', 'note' => '', 'source_url' => ''];

    public string $message = '';

    public function save(): void
    {
        $data = validator($this->form, [
            'kind' => ['required', 'in:'.implode(',', array_keys(ChartAnnotations::KINDS))],
            'brand_id' => ['nullable', 'integer', 'exists:brands,id'],
            'starts_on' => ['required', 'date'],
            'ends_on' => ['nullable', 'date', 'after_or_equal:starts_on'],
            'title' => ['required', 'string', 'max:200'],
            'note' => ['nullable', 'string', 'max:2000'],
            'source_url' => ['nullable', 'url', 'max:500'],
        ])->after(function ($validator): void {
            if (! in_array($this->form['kind'], ChartAnnotations::GLOBAL_KINDS, true) && blank($this->form['brand_id'])) {
                $validator->errors()->add('brand_id', 'Kampanya ve site notları bir markaya bağlanır.');
            }
        })->validate();
        DB::table('chart_annotations')->insert([
            'brand_id' => filled($data['brand_id'] ?? null) ? (int) $data['brand_id'] : null,
            'kind' => $data['kind'], 'starts_on' => $data['starts_on'], 'ends_on' => $data['ends_on'] ?: null,
            'title' => $data['title'], 'note' => $data['note'] ?: null, 'source_url' => $data['source_url'] ?: null,
            'created_by' => auth()->id(), 'created_at' => now(), 'updated_at' => now(),
        ]);
        $this->form = ['brand_id' => $this->form['brand_id'], 'kind' => $this->form['kind'], 'starts_on' => '', 'ends_on' => '', 'title' => '', 'note' => '', 'source_url' => ''];
        $this->message = 'Not eklendi.';
    }

    public function delete(int $id): void
    {
        $row = DB::table('chart_annotations')->find($id) ?? abort(404);
        abort_unless((int) $row->created_by === (int) auth()->id() || auth()->user()?->hasRole(Roles::ADMIN), 403);
        DB::table('chart_annotations')->where('id', $id)->delete();
        $this->message = 'Not silindi.';
    }

    public function render(): View
    {
        $brands = Brand::query()->whereHas('customer', fn ($q) => $q->where('status', CustomerStatus::Active->value))->orderBy('name')->pluck('name', 'id');

        return view('livewire.operator.reports.chart-annotations', [
            'rows' => DB::table('chart_annotations')
                ->when($this->filter === 'global', fn ($q) => $q->whereNull('brand_id'))
                ->when(ctype_digit($this->filter), fn ($q) => $q->where('brand_id', (int) $this->filter))
                ->orderByDesc('starts_on')->orderByDesc('id')->limit(200)->get(),
            'brands' => $brands,
            'kinds' => ChartAnnotations::KINDS,
        ]);
    }
}
