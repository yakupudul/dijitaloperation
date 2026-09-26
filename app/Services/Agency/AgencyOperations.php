<?php

namespace App\Services\Agency;

use App\Enums\CustomerStatus;
use App\Models\ContentCalendarItem;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\ServiceCommitment;
use App\Models\TimeEntry;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Ajans işletmesi: profitability (fee against time spent), monthly commitments and their progress, and invoices.
 */
final class AgencyOperations
{
    public function hourlyCost(): ?float
    {
        if (! Schema::hasColumn('agency_settings', 'hourly_cost')) {
            return null;
        }
        $value = DB::table('agency_settings')->value('hourly_cost');

        return $value !== null ? (float) $value : null;
    }

    /**
     * @return list<array{customer_id: int, customer: string, fee: ?float, hours: float, cost: ?float, margin: ?float, rate: ?float, state: string}>
     */
    public function profitability(string $month): array
    {
        [$from, $to] = $this->range($month);
        $minutes = TimeEntry::query()->whereBetween('worked_on', [$from->toDateString(), $to->toDateString()])
            ->selectRaw('customer_id, sum(minutes) as m')->groupBy('customer_id')->pluck('m', 'customer_id');
        $hourly = $this->hourlyCost();
        $rows = [];
        foreach (Customer::query()->where('status', CustomerStatus::Active->value)->orderBy('name')->get(['id', 'name', 'monthly_fee']) as $customer) {
            $hours = round(((int) ($minutes[$customer->id] ?? 0)) / 60, 1);
            $fee = $customer->monthly_fee !== null ? (float) $customer->monthly_fee : null;
            if ($fee === null && $hours <= 0) {
                continue;
            }
            $cost = $hourly !== null ? round($hours * $hourly, 2) : null;
            $margin = $fee !== null && $cost !== null ? round($fee - $cost, 2) : null;
            $rows[] = [
                'customer_id' => $customer->id, 'customer' => $customer->name, 'fee' => $fee, 'hours' => $hours, 'cost' => $cost, 'margin' => $margin,
                'rate' => $fee !== null && $hours > 0 ? round($fee / $hours, 0) : null,
                'state' => match (true) {
                    $margin === null => 'unknown', $margin < 0 => 'loss', $fee > 0 && $margin / $fee < 0.3 => 'thin', default => 'ok',
                },
            ];
        }
        usort($rows, fn (array $a, array $b): int => [['loss' => 0, 'thin' => 1, 'unknown' => 2, 'ok' => 3][$a['state']], -$a['hours']] <=> [['loss' => 0, 'thin' => 1, 'unknown' => 2, 'ok' => 3][$b['state']], -$b['hours']]);

        return $rows;
    }

    /**
     * @return list<array{id: int, customer_id: int, customer: string, brand: ?string, title: string, quantity: int, done: int, manual: int, auto: int, counts_from: ?string, state: string}>
     */
    public function commitments(string $month): array
    {
        [$from, $to] = $this->range($month);
        $marks = DB::table('service_commitment_marks')->where('month', $month)->pluck('done', 'service_commitment_id');
        $rows = [];
        foreach (ServiceCommitment::query()->with(['customer', 'brand'])->where('active', true)->orderBy('customer_id')->get() as $commitment) {
            $auto = 0;
            if ($commitment->counts_from !== null) {
                $auto = ContentCalendarItem::query()->where('channel', $commitment->counts_from)->where('status', 'published')
                    ->whereBetween('published_at', [$from, $to])
                    ->when($commitment->brand_id !== null, fn ($q) => $q->where('brand_id', $commitment->brand_id),
                        fn ($q) => $q->whereIn('brand_id', $commitment->customer?->brands()->pluck('id') ?? []))->count();
            }
            $manual = (int) ($marks[$commitment->id] ?? 0);
            $done = $auto + $manual;
            $monthProgress = $this->monthProgress($month);
            $rows[] = [
                'id' => $commitment->id, 'customer_id' => $commitment->customer_id, 'customer' => (string) $commitment->customer?->name, 'brand' => $commitment->brand?->name,
                'title' => $commitment->title, 'quantity' => $commitment->monthly_quantity, 'done' => $done, 'manual' => $manual, 'auto' => $auto, 'counts_from' => $commitment->counts_from,
                'state' => match (true) {
                    $done >= $commitment->monthly_quantity => 'done',
                    $done < $commitment->monthly_quantity * $monthProgress - 0.5 => 'behind',
                    default => 'on_track',
                },
            ];
        }

        return $rows;
    }

    public function markCommitment(int $commitmentId, string $month, int $delta): void
    {
        $row = DB::table('service_commitment_marks')->where('service_commitment_id', $commitmentId)->where('month', $month)->first();
        $done = max(0, (int) ($row->done ?? 0) + $delta);
        DB::table('service_commitment_marks')->updateOrInsert(['service_commitment_id' => $commitmentId, 'month' => $month], ['done' => $done, 'updated_at' => now(), 'created_at' => $row->created_at ?? now()]);
    }

    /** Day 1: a draft invoice for every active customer with a monthly fee. Returns how many were created. */
    public function draftMonthlyInvoices(?string $period = null): int
    {
        $period ??= now()->format('Y-m');
        $created = 0;
        foreach (Customer::query()->where('status', CustomerStatus::Active->value)->whereNotNull('monthly_fee')->where('monthly_fee', '>', 0)->get() as $customer) {
            if (Invoice::query()->where('customer_id', $customer->id)->where('period', $period)->exists()) {
                continue;
            }
            Invoice::query()->create(['customer_id' => $customer->id, 'period' => $period, 'amount' => $customer->monthly_fee, 'status' => 'draft',
                'due_on' => CarbonImmutable::createFromFormat('Y-m-d', $period.'-01')->addDays(14)->toDateString()]);
            $created++;
        }

        return $created;
    }

    /** Share of the month elapsed (0–1). */
    private function monthProgress(string $month): float
    {
        [$from, $to] = $this->range($month);
        if (now()->gt($to)) {
            return 1.0;
        }
        if (now()->lt($from)) {
            return 0.0;
        }

        return now()->day / $to->day;
    }

    /** @return array{0: CarbonImmutable, 1: CarbonImmutable} */
    private function range(string $month): array
    {
        $from = CarbonImmutable::createFromFormat('Y-m-d', $month.'-01')->startOfMonth();

        return [$from, $from->endOfMonth()];
    }
}
