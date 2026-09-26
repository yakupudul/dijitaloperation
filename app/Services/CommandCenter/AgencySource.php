<?php

namespace App\Services\CommandCenter;

use App\Models\CustomerInteraction;
use App\Models\Invoice;
use App\Services\Agency\AgencyOperations;
use Illuminate\Support\Collection;

/** Ajans işletmesi in the command center: follow-ups due, overdue invoices, drafts not issued, commitments behind. */
final class AgencySource implements CommandCenterSource
{
    public function __construct(private readonly AgencyOperations $operations) {}

    public function items(): Collection
    {
        $out = collect();
        foreach (CustomerInteraction::query()->with('customer')->whereNotNull('next_action_at')->whereNull('next_action_done_at')
            ->where('next_action_at', '<=', now()->endOfDay())->orderBy('next_action_at')->limit(100)->get() as $row) {
            $out->push(CommandCenter::item('followup', $row->id, $row->next_action_at->lt(now()->startOfDay()) ? 'high' : 'medium', 'Takip: '.$row->next_action, [
                'detail' => $row->customer?->name.' · '.mb_substr((string) $row->summary, 0, 160),
                'brand_id' => $row->brand_id,
                'channel' => 'Müşteri iletişimi',
                'url' => route('operator.customer', ['customerId' => $row->customer_id]),
                'actions' => ['done', 'snooze'],
                'age' => $row->next_action_at,
            ]));
        }
        foreach (Invoice::query()->with('customer')->where('status', 'issued')->whereNotNull('due_on')->where('due_on', '<', now()->toDateString())->limit(100)->get() as $invoice) {
            $out->push(CommandCenter::item('invoice', $invoice->id, 'high', 'Vadesi geçen fatura: '.$invoice->customer?->name.' · '.number_format((float) $invoice->amount, 0, ',', '.').' '.$invoice->currency, [
                'detail' => $invoice->period.' dönemi, vade '.$invoice->due_on->format('d.m.Y').'.',
                'channel' => 'Tahsilat',
                'money' => (float) $invoice->amount,
                'url' => route('operator.agency', ['tab' => 'invoices']),
                'actions' => ['done', 'snooze'],
                'age' => $invoice->due_on,
            ]));
        }
        $drafts = Invoice::query()->where('status', 'draft')->where('period', '<=', now()->format('Y-m'))->count();
        if ($drafts > 0 && now()->day >= 3) {
            $out->push(CommandCenter::item('invoice', 'drafts', 'medium', $drafts.' taslak fatura kesilmeyi bekliyor', [
                'channel' => 'Tahsilat', 'url' => route('operator.agency', ['tab' => 'invoices']),
            ]));
        }
        foreach ($this->operations->commitments(now()->format('Y-m')) as $row) {
            if ($row['state'] === 'behind') {
                $out->push(CommandCenter::item('commitment', $row['id'], now()->day >= 22 ? 'high' : 'medium', 'Taahhüt geride: '.$row['title'].' ('.$row['done'].'/'.$row['quantity'].')', [
                    'detail' => $row['customer'].($row['brand'] ? ' · '.$row['brand'] : '').' — bu ay için söz verilen iş.',
                    'channel' => 'Taahhüt',
                    'url' => route('operator.agency', ['tab' => 'commitments']),
                ]));
            }
        }

        return $out;
    }
}
