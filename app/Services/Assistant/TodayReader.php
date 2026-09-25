<?php

namespace App\Services\Assistant;

use App\Models\AssetAlert;
use App\Models\AssetRenewal;
use App\Models\Customer;
use App\Models\Prospect;
use App\Models\Reminder;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Bugün: what needs the owner today, in one read — sites down, due reminders, renewals within 30 days,
 * WhatsApp conversations waiting for a reply, prospects to follow up and customers to contact (critical
 * alert, or linked WhatsApp silent for 30+ days).
 */
final class TodayReader
{
    /** @return array<string, mixed> */
    public function read(User $user): array
    {
        return [
            'sites_down' => $this->sitesDown(),
            'reminders' => Reminder::query()->with(['customer:id,name', 'brand:id,name'])->where('user_id', $user->id)->whereNull('done_at')
                ->where('remind_at', '<=', now()->endOfDay())->orderBy('remind_at')->limit(20)->get(),
            'upcoming_reminders' => Reminder::query()->where('user_id', $user->id)->whereNull('done_at')
                ->whereBetween('remind_at', [now()->endOfDay(), now()->addDays(7)])->orderBy('remind_at')->limit(10)->get(),
            'renewals' => AssetRenewal::query()->with('brand:id,name')->whereNotNull('expires_on')->where('expires_on', '<=', now()->addDays(30))
                ->where(fn ($q) => $q->where('auto_renew', false)->orWhere('expires_on', '<=', now()->addDays(7)))->orderBy('expires_on')->limit(15)->get(),
            'whatsapp_waiting' => $this->whatsappWaiting(),
            'follow_ups' => Prospect::query()->whereNotNull('next_follow_up_on')->whereDate('next_follow_up_on', '<=', now()->toDateString())
                ->whereNotIn('status', ['won', 'lost'])->orderBy('next_follow_up_on')->limit(15)->get(),
            'contact' => $this->customersToContact(),
            'calendar_url' => $user->calendar_feed_token ? route('calendar.feed', ['token' => $user->calendar_feed_token]) : null,
        ];
    }

    /** @return list<array{asset_id: int, name: string, brand: ?string, since: ?string, error: ?string}> */
    private function sitesDown(): array
    {
        if (! Schema::hasTable('uptime_states')) {
            return [];
        }

        return DB::table('uptime_states as u')->join('digital_assets as a', 'a.id', '=', 'u.digital_asset_id')->leftJoin('brands as b', 'b.id', '=', 'a.brand_id')
            ->where('u.state', 'down')->orderBy('u.down_since')->get(['a.id', 'a.name', 'a.domain', 'b.name as brand', 'u.down_since', 'u.last_error'])
            ->map(fn (object $row): array => ['asset_id' => (int) $row->id, 'name' => (string) ($row->domain ?: $row->name), 'brand' => $row->brand, 'since' => $row->down_since, 'error' => $row->last_error])
            ->all();
    }

    /** Conversations whose latest message (last 7 days) is from the other side. @return list<array<string, mixed>> */
    private function whatsappWaiting(): array
    {
        if (! Schema::hasTable('whatsapp_conversations')) {
            return [];
        }
        $out = [];
        $conversations = DB::table('whatsapp_conversations')->where('last_message_at', '>=', now()->subDays(7))->orderByDesc('last_message_at')->limit(50)
            ->get(['id', 'contact_name', 'contact_id', 'last_message_at', 'customer_id', 'prospect_id']);
        $customers = Customer::query()->whereIn('id', $conversations->pluck('customer_id')->filter())->pluck('name', 'id');
        $prospects = Prospect::query()->whereIn('id', $conversations->pluck('prospect_id')->filter())->pluck('company_name', 'id');
        foreach ($conversations as $conversation) {
            $last = DB::table('whatsapp_messages')->where('conversation_id', $conversation->id)->orderByDesc('sent_at')->orderByDesc('id')->value('direction');
            if ($last === 'incoming') {
                $out[] = [
                    'id' => (int) $conversation->id, 'name' => (string) ($conversation->contact_name ?: $conversation->contact_id),
                    'who' => $conversation->customer_id ? 'Müşteri: '.($customers[$conversation->customer_id] ?? '') : ($conversation->prospect_id ? 'Aday: '.($prospects[$conversation->prospect_id] ?? '') : null),
                    'at' => $conversation->last_message_at,
                ];
            }
        }

        return array_slice($out, 0, 10);
    }

    /** @return list<array{customer_id: int, name: string, reason: string}> */
    private function customersToContact(): array
    {
        $rows = [];
        $critical = AssetAlert::query()->active()->whereIn('severity', ['critical', 'high'])->whereNotNull('brand_id')
            ->with('brand:id,name,customer_id')->get()->groupBy(fn (AssetAlert $a) => $a->brand?->customer_id);
        $names = Customer::query()->whereIn('id', $critical->keys()->filter())->where('status', 'active')->pluck('name', 'id');
        foreach ($critical as $customerId => $alerts) {
            if (isset($names[$customerId])) {
                $rows[(int) $customerId] = ['customer_id' => (int) $customerId, 'name' => (string) $names[$customerId],
                    'reason' => 'Bilgilendir: '.$alerts->pluck('title')->unique()->take(2)->implode(', ')];
            }
        }
        // Faz 10b: customers whose health score fell into the risk band come first with their main reason.
        if (Schema::hasTable('customer_health')) {
            foreach (DB::table('customer_health')->join('customers', 'customers.id', '=', 'customer_health.customer_id')->where('customers.status', 'active')->whereNull('customers.deleted_at')
                ->where('customer_health.band', 'risk')->orderBy('customer_health.score')->limit(10)->get(['customers.id', 'customers.name', 'customer_health.score', 'customer_health.reasons']) as $row) {
                $reason = (array) (json_decode((string) $row->reasons, true)[0] ?? []);
                $rows = [(int) $row->id => ['customer_id' => (int) $row->id, 'name' => (string) $row->name, 'reason' => 'Sağlık puanı '.$row->score.': '.($reason['text'] ?? '')]] + $rows;
            }
        }
        if (Schema::hasColumn('whatsapp_conversations', 'customer_id')) {
            $lastContact = DB::table('whatsapp_conversations')->whereNotNull('customer_id')->groupBy('customer_id')->selectRaw('customer_id, max(last_message_at) as last_at')->pluck('last_at', 'customer_id');
            $silent = $lastContact->filter(fn ($at): bool => $at === null || strtotime((string) $at) < now()->subDays(30)->getTimestamp());
            foreach (Customer::query()->whereIn('id', $silent->keys())->where('status', 'active')->get(['id', 'name']) as $customer) {
                $rows[$customer->id] ??= ['customer_id' => (int) $customer->id, 'name' => (string) $customer->name,
                    'reason' => '30+ gündür WhatsApp teması yok (son: '.Carbon::parse((string) $silent[$customer->id])->format('d.m.Y').')'];
            }
        }

        return array_values(array_slice($rows, 0, 10, true));
    }
}
