<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * What a referral earned (moximu.com Referans programı): a customer who referred gets one month of their own service
 * free (gift_month), anyone else gets %10 of the new customer's first monthly payment, once (commission). It waits
 * until the new customer has paid twice, then is earned; the operator ticks it paid (or applies the gift month).
 */
class ReferralReward extends Model
{
    public const array KINDS = ['gift_month' => '1 ay hediye', 'commission' => 'İlk ödemenin %10\'u'];

    public const array STATUSES = ['waiting' => 'İkinci ödeme bekleniyor', 'earned' => 'Hak edildi', 'paid' => 'Verildi', 'void' => 'Düştü'];

    /** Share of the new customer's first payment a non-customer referrer earns (yakup 2026-11-08). */
    public const float COMMISSION_RATE = 0.10;

    /** Paid months of the new customer before the reward is given (moximu.com: "klinik ikinci aylık ödemesini yaptığında"). */
    public const int PAYMENTS_NEEDED = 2;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['earned_at' => 'datetime', 'paid_at' => 'datetime', 'amount' => 'integer'];
    }

    /** @return BelongsTo<SalesLead, $this> */
    public function lead(): BelongsTo
    {
        return $this->belongsTo(SalesLead::class, 'lead_id');
    }

    /** @return BelongsTo<Customer, $this> */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /** @return BelongsTo<Customer, $this> */
    public function referrerCustomer(): BelongsTo
    {
        return $this->belongsTo(Customer::class, 'referrer_customer_id');
    }

    /** @return BelongsTo<Referrer, $this> */
    public function referrer(): BelongsTo
    {
        return $this->belongsTo(Referrer::class);
    }

    public function referrerName(): string
    {
        return (string) ($this->referrerCustomer?->name ?? $this->referrer?->name ?? '–');
    }

    public function kindLabel(): string
    {
        return self::KINDS[$this->kind] ?? $this->kind;
    }

    public function statusLabel(): string
    {
        return self::STATUSES[$this->status] ?? $this->status;
    }
}
