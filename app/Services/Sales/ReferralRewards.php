<?php

namespace App\Services\Sales;

use App\Models\Agreement;
use App\Models\AgreementPayment;
use App\Models\ReferralReward;
use App\Models\SalesLead;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * Referans ödülleri (yakup 2026-11-08, moximu.com Referans programı): a referred record that becomes a customer earns
 * its referrer a reward. A customer who referred: one month of their own service free; anyone else: %10 of the new
 * customer's first monthly payment, once. The reward waits until the new customer has paid twice, then it is earned;
 * the operator gives it (applies the gift month to the referrer's next unpaid month, or ticks the commission paid).
 */
final class ReferralRewards
{
    private const string TZ = 'Europe/Istanbul';

    /** The record became a customer (or got its referrer after it did): its reward row, once. */
    public function onWon(SalesLead $lead): ?ReferralReward
    {
        if ($lead->came_via !== 'referral' || ($lead->referrer_customer_id === null && $lead->referrer_id === null)
            || ! in_array($lead->lifecycle, ['customer', 'former'], true) || $lead->customer_id === null) {
            return null;
        }
        $reward = ReferralReward::query()->firstOrNew(['lead_id' => $lead->id]);
        if ($reward->exists && in_array($reward->status, ['earned', 'paid'], true)) {
            return $reward;
        }
        $reward->fill([
            'customer_id' => $lead->customer_id,
            'referrer_customer_id' => $lead->referrer_customer_id,
            'referrer_id' => $lead->referrer_customer_id === null ? $lead->referrer_id : null,
            'kind' => $lead->referrer_customer_id !== null ? 'gift_month' : 'commission',
        ]);
        $reward->status = $reward->status ?: 'waiting';
        $reward->save();
        $this->check($reward);

        return $reward->fresh();
    }

    /** A payment was ticked: every waiting reward of that customer is checked. */
    public function checkCustomer(?int $customerId): void
    {
        if ($customerId === null) {
            return;
        }
        ReferralReward::query()->where('customer_id', $customerId)->where('status', 'waiting')->get()->each(fn (ReferralReward $r) => $this->check($r));
    }

    /** Earned once the new customer has paid PAYMENTS_NEEDED months; the commission is %10 of the first paid month. */
    public function check(ReferralReward $reward): void
    {
        if ($reward->status !== 'waiting' || $reward->customer_id === null) {
            return;
        }
        $paid = AgreementPayment::query()->whereNotNull('paid_on')->where('amount', '>', 0)
            ->whereHas('agreement', fn ($q) => $q->where('customer_id', $reward->customer_id)->whereIn('kind', ['monthly', 'term']))
            ->orderBy('period')->get();
        if ($paid->count() < ReferralReward::PAYMENTS_NEEDED) {
            return;
        }
        $amount = $reward->kind === 'commission'
            ? (int) round((int) $paid->first()->amount * ReferralReward::COMMISSION_RATE)
            : $this->monthlyFee((int) $reward->referrer_customer_id);
        $reward->forceFill(['status' => 'earned', 'earned_at' => now(), 'amount' => $amount ?: null])->save();
    }

    /**
     * "Ver": a commission is ticked paid; a gift month goes onto the referrer's next unpaid month (its amount becomes
     * 0 ₺ with a note), so the agreement ticks and the month report show it as given.
     */
    public function give(ReferralReward $reward, ?User $user = null): void
    {
        if ($reward->status !== 'earned') {
            throw ValidationException::withMessages(['reward' => 'Ödül henüz hak edilmedi ya da zaten verildi.']);
        }
        if ($reward->kind === 'gift_month') {
            $payment = $this->nextUnpaid((int) $reward->referrer_customer_id);
            if ($payment === null) {
                throw ValidationException::withMessages(['reward' => 'Referans veren müşterinin aktif aylık anlaşması yok; hediye ayı uygulanamadı.']);
            }
            $payment->forceFill(['amount' => 0, 'note' => mb_substr('Referans hediyesi: '.($reward->customer?->name ?? 'yeni müşteri'), 0, 255)])->save();
            $reward->gift_payment_id = $payment->id;
        }
        $reward->forceFill(['status' => 'paid', 'paid_at' => now()])->save();
    }

    public function void(ReferralReward $reward, ?string $note = null): void
    {
        $reward->forceFill(['status' => 'void', 'note' => $note !== null ? mb_substr($note, 0, 500) : $reward->note])->save();
    }

    /** @return Collection<int, ReferralReward> newest first, with the names the table shows */
    public function all(): Collection
    {
        return ReferralReward::query()->with(['lead:id,name,company,phone,website_host', 'customer:id,name', 'referrerCustomer:id,name', 'referrer:id,name'])
            ->orderByRaw("case status when 'earned' then 0 when 'waiting' then 1 when 'paid' then 2 else 3 end")->orderByDesc('id')->limit(200)->get();
    }

    private function monthlyFee(int $customerId): int
    {
        return (int) Agreement::query()->where('customer_id', $customerId)->active()->whereIn('kind', ['monthly', 'term'])->sum('fee');
    }

    private function nextUnpaid(int $customerId): ?AgreementPayment
    {
        $agreement = Agreement::query()->where('customer_id', $customerId)->active()->whereIn('kind', ['monthly', 'term'])->orderBy('id')->first();
        if ($agreement === null) {
            return null;
        }
        $agreements = app(Agreements::class);
        $agreements->ensurePayments($agreement, CarbonImmutable::now(self::TZ)->addMonth());

        return $agreement->payments()->whereNull('paid_on')->where('amount', '>', 0)->orderBy('period')->first();
    }
}
