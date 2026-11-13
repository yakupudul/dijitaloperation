<?php

namespace App\Services\Sales;

use App\Models\Agreement;
use App\Models\AgreementPayment;
use App\Models\Brand;
use App\Models\Customer;
use App\Models\SalesLead;
use App\Models\SalesOpportunity;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * Anlaşmalar (yakup 2026-11-07): a brand's working terms — aylık, dönemlik (6 ay) or tek seferlik — entered on the
 * brand, created by itself when a record is won, shared by old and new customers alike. Each month gets one payment
 * row to tick; an unticked row past its day is "gecikmiş tahsilat". customers.monthly_fee is kept equal to the sum of
 * the customer's active monthly agreements so every older screen keeps reading the same number.
 */
final class Agreements
{
    public const string TZ = 'Europe/Istanbul';

    /** Days before a term ends that the brand shows "dönem bitiyor". */
    public const int ENDING_SOON_DAYS = 30;

    /** Kinds that never replace the brand's running agreement: they are jobs of their own beside it. */
    public const array BESIDE_KINDS = ['one_time', 'yearly'];

    /**
     * Starts an agreement on the brand; the brand's earlier active one ends the day before. Validates what the operator typed.
     *
     * @param  array{kind?: string, fee?: int|string, term_months?: int|string|null, starts_on?: string, due_day?: int|string, services?: array<int, string>|null, note?: string|null}  $data
     */
    public function start(?Brand $brand, array $data, ?User $user = null, ?SalesLead $lead = null, ?SalesOpportunity $opportunity = null): Agreement
    {
        $clean = $this->clean($data, $brand === null);
        $startsOn = CarbonImmutable::parse($clean['starts_on'], self::TZ)->startOfDay();
        // A new monthly / term agreement replaces the running one (a raise, a renewal); a one-time or yearly job sits
        // beside it, and so does anything the form marks as a job of its own ("beside").
        if ($brand !== null && ! in_array($clean['kind'], self::BESIDE_KINDS, true) && empty($data['beside'])) {
            foreach (Agreement::query()->where('brand_id', $brand->id)->active()->whereNotIn('kind', self::BESIDE_KINDS)->get() as $previous) {
                $previous->forceFill(['status' => 'ended', 'ends_on' => $previous->ends_on !== null && $previous->ends_on->lt($startsOn) ? $previous->ends_on : $startsOn->subDay()->toDateString()])->save();
                $this->trimPayments($previous);
            }
        }
        $agreement = Agreement::query()->create([
            'brand_id' => $brand?->id, 'customer_id' => $brand?->customer_id, 'lead_id' => $lead?->id, 'kind' => $clean['kind'], 'fee' => $clean['fee'],
            'term_months' => $clean['term_months'], 'starts_on' => $startsOn->toDateString(), 'ends_on' => self::endsOn($clean['kind'], $startsOn, $clean['term_months']),
            'due_day' => $clean['due_day'], 'services' => $clean['services'], 'status' => 'active', 'note' => $clean['note'],
            'title' => $clean['title'], 'party' => $clean['party'], 'party_phone' => $clean['party_phone'], 'tax_no' => $clean['tax_no'],
            'plan' => $clean['plan'], 'total' => $clean['total'], 'fee_since' => $startsOn->toDateString(), 'no_invoice' => $clean['no_invoice'],
        ]);
        if ($opportunity !== null) {
            $opportunity->forceFill(['agreement_id' => $agreement->id])->save();
        }
        if ($agreement->isSchedule()) {
            $this->setSchedule($agreement, $clean['installments']);
        } else {
            $this->ensurePayments($agreement);
        }
        $this->syncCustomer($brand?->customer_id);

        return $agreement;
    }

    /**
     * The taksit plan: the rows become the dates and amounts typed. Rows money already came in for stay as they are;
     * the open ones are replaced. The agreement's total follows the rows.
     *
     * @param  list<array{due_on: string, amount: int|string, label?: ?string}>  $rows
     */
    public function setSchedule(Agreement $agreement, array $rows): void
    {
        $rows = $this->cleanInstallments($rows);
        $agreement->payments()->whereNull('paid_on')->where('paid_amount', 0)->delete();
        $kept = $agreement->payments()->get();
        $n = $kept->count();
        foreach ($rows as $row) {
            $n++;
            AgreementPayment::query()->create([
                'agreement_id' => $agreement->id, 'period' => substr($row['due_on'], 0, 7), 'amount' => $row['amount'], 'due_on' => $row['due_on'],
                'label' => $row['label'] ?? (count($rows) + $kept->count() > 1 ? $n.'. taksit' : null),
            ]);
        }
        $agreement->forceFill(['plan' => 'schedule', 'total' => (int) $kept->sum('amount') + (int) array_sum(array_column($rows, 'amount'))])->save();
        $agreement->unsetRelation('payments');
    }

    /**
     * Money came in, whatever the amount and the day: it fills the open rows from the oldest. A monthly job opens its
     * coming months when the amount is more than what is open (a customer paying ahead). What is left over after every
     * row is returned, so the caller can say it.
     *
     * @return array{applied: int, left: int, rows: list<int>}
     */
    public function receive(Agreement $agreement, int $amount, ?string $on = null, ?string $note = null): array
    {
        if ($amount <= 0) {
            throw ValidationException::withMessages(['amount' => 'Gelen tutarı yaz.']);
        }
        $day = ($on !== null && $on !== '' ? CarbonImmutable::parse($on, self::TZ) : CarbonImmutable::now(self::TZ))->toDateString();
        $this->ensurePayments($agreement);
        $left = $amount;
        $touched = [];
        $open = fn () => $agreement->payments()->whereNull('paid_on')->orderBy('due_on')->orderBy('id')->get();
        $rows = $open();
        // Paying ahead on an open-ended plan: the next periods are opened (at most two years) until the money is placed.
        for ($ahead = 1; ! $agreement->isSchedule() && in_array($agreement->kind, ['monthly', 'term'], true) && $agreement->isActive()
            && $left > (int) $rows->sum(fn (AgreementPayment $p): int => $p->open()) && $ahead <= 24; $ahead++) {
            if ($this->ensurePayments($agreement, CarbonImmutable::now(self::TZ)->addMonths($ahead)) === 0 && $agreement->ends_on !== null) {
                break;
            }
            $rows = $open();
        }
        foreach ($rows as $row) {
            if ($left <= 0) {
                break;
            }
            $take = min($left, $row->open());
            $left -= $take;
            $touched[] = $row->id;
            $paid = (int) $row->paid_amount + $take;
            $row->forceFill(['paid_amount' => $paid, 'note' => $note !== null && trim($note) !== '' ? mb_substr(trim($note), 0, 255) : $row->note]
                + ($paid >= (int) $row->amount ? ['paid_on' => $day] : []))->save();
        }
        $agreement->unsetRelation('payments');
        $this->afterPaid($agreement);

        return ['applied' => $amount - $left, 'left' => $left, 'rows' => $touched];
    }

    /** A one-time job ends once nothing of it is open; a referred customer's payment may earn the referrer's reward. */
    private function afterPaid(?Agreement $agreement): void
    {
        if ($agreement === null) {
            return;
        }
        if ($agreement->kind === 'one_time' && $agreement->isActive() && ! $agreement->payments()->whereNull('paid_on')->exists()) {
            $agreement->forceFill(['status' => 'ended'])->save();
            $this->syncCustomer($agreement->customer_id);
        }
        if ($agreement->customer_id !== null) {
            app(ReferralRewards::class)->checkCustomer($agreement->customer_id);
        }
    }

    /** Changes the terms in place (a raise, a new due day, a longer term). */
    public function update(Agreement $agreement, array $data): Agreement
    {
        $clean = $this->clean(array_merge([
            'kind' => $agreement->kind, 'fee' => $agreement->fee, 'term_months' => $agreement->term_months, 'starts_on' => $agreement->starts_on?->toDateString(),
            'due_day' => $agreement->due_day, 'services' => $agreement->services, 'note' => $agreement->note,
            'title' => $agreement->title, 'party' => $agreement->party, 'party_phone' => $agreement->party_phone, 'tax_no' => $agreement->tax_no,
            'plan' => $agreement->plan, 'total' => $agreement->total, 'no_invoice' => $agreement->no_invoice,
        ], $data), $agreement->brand_id === null, false);
        $startsOn = CarbonImmutable::parse($clean['starts_on'], self::TZ)->startOfDay();
        $raised = (int) $clean['fee'] !== (int) $agreement->fee;
        $agreement->forceFill([
            'title' => $clean['title'], 'party' => $clean['party'], 'party_phone' => $clean['party_phone'], 'tax_no' => $clean['tax_no'], 'plan' => $clean['plan'], 'no_invoice' => $clean['no_invoice'],
            // A changed fee starts its own clock ("bu ücretle ne zamandır"); the form may give the day it began.
            'fee_since' => $raised ? (preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($data['fee_since'] ?? '')) === 1 ? $data['fee_since'] : CarbonImmutable::now(self::TZ)->toDateString())
                : ($agreement->fee_since?->toDateString() ?? $agreement->starts_on?->toDateString()),
            'kind' => $clean['kind'], 'fee' => $clean['fee'], 'term_months' => $clean['term_months'], 'starts_on' => $startsOn->toDateString(),
            'ends_on' => $agreement->status === 'ended' ? $agreement->ends_on?->toDateString() : self::endsOn($clean['kind'], $startsOn, $clean['term_months']),
            'due_day' => $clean['due_day'], 'services' => $clean['services'], 'note' => $clean['note'],
        ])->save();
        if (isset($data['installments']) && $agreement->isSchedule()) {
            $this->setSchedule($agreement, (array) $data['installments']);
        } elseif (! $agreement->isSchedule()) {
            // Unpaid rows that have not begun to be paid follow the new fee and day; paid ones keep what came in. A raise
            // that begins on a later day (fee_since) leaves the months before it at the old fee.
            $from = $raised ? substr((string) $agreement->fee_since?->toDateString(), 0, 7) : '';
            foreach ($agreement->payments()->whereNull('paid_on')->where('paid_amount', 0)->get() as $payment) {
                $payment->forceFill(['due_on' => $agreement->kind === 'one_time' ? $startsOn->toDateString() : $agreement->dueIn(CarbonImmutable::parse($payment->period.'-01', self::TZ))->toDateString()]
                    + ($payment->period >= $from ? ['amount' => $agreement->fee] : []))->save();
            }
            $this->trimPayments($agreement);
            $this->ensurePayments($agreement);
        }
        $this->syncCustomer($agreement->customer_id);

        return $agreement;
    }

    /** "Durdur" / "Bitir" / "Sürdür". Ending removes the unpaid months after the end; a customer with nothing active becomes a former one. */
    public function setStatus(Agreement $agreement, string $status, ?string $on = null): void
    {
        if (! isset(Agreement::STATUSES[$status])) {
            throw ValidationException::withMessages(['status' => 'Durum seçeneklerden biri olmalı.']);
        }
        $agreement->status = $status;
        if ($status === 'ended') {
            $agreement->ends_on = ($on !== null ? CarbonImmutable::parse($on, self::TZ) : CarbonImmutable::now(self::TZ))->toDateString();
        } elseif ($status === 'active' && $agreement->kind !== 'term') {
            $agreement->ends_on = null;
        }
        $agreement->save();
        $this->trimPayments($agreement);
        if ($status === 'active') {
            $this->ensurePayments($agreement);
        }
        $this->syncCustomer($agreement->customer_id);
    }

    /** One row per billed month up to now (and the next month once its day is within ENDING_SOON_DAYS? no: up to this month). */
    public function ensurePayments(Agreement $agreement, ?CarbonImmutable $until = null): int
    {
        if (! $agreement->isActive() && $agreement->status !== 'paused') {
            return 0;
        }
        $until ??= CarbonImmutable::now(self::TZ);
        $known = $agreement->payments()->pluck('id', 'period');
        $created = 0;
        foreach ($agreement->periodsUntil($until) as $period) {
            if (isset($known[$period])) {
                continue;
            }
            $month = CarbonImmutable::parse($period.'-01', self::TZ);
            $due = $agreement->kind === 'one_time' ? CarbonImmutable::instance($agreement->starts_on) : $agreement->dueIn($month);
            AgreementPayment::query()->create(['agreement_id' => $agreement->id, 'period' => $period, 'amount' => (int) $agreement->fee, 'due_on' => $due->toDateString()]);
            $created++;
        }

        return $created;
    }

    public function markPaid(AgreementPayment $payment, ?string $on = null, ?string $note = null): void
    {
        $payment->forceFill(['paid_on' => ($on !== null ? CarbonImmutable::parse($on, self::TZ) : CarbonImmutable::now(self::TZ))->toDateString(),
            'paid_amount' => (int) $payment->amount, 'note' => $note !== null ? mb_substr(trim($note), 0, 255) : $payment->note])->save();
        // A one-time job is done once all of it is paid; a referred customer's second paid month earns the referrer's reward.
        $this->afterPaid($payment->loadMissing('agreement')->agreement);
    }

    public function unpay(AgreementPayment $payment): void
    {
        $payment->forceFill(['paid_on' => null, 'paid_amount' => 0])->save();
        $agreement = $payment->loadMissing('agreement')->agreement;
        if ($agreement !== null && $agreement->kind === 'one_time' && $agreement->status === 'ended') {
            // The tick was a slip: the job is open again.
            $agreement->forceFill(['status' => 'active'])->save();
        }
    }

    /** customers.monthly_fee = the sum of the customer's active monthly agreements (older screens read it). */
    public function syncCustomer(?int $customerId): void
    {
        if ($customerId === null) {
            return;
        }
        $customer = Customer::query()->find($customerId);
        if ($customer === null) {
            return;
        }
        $agreements = Agreement::query()->where('customer_id', $customerId)->get();
        $mrr = $agreements->sum(fn (Agreement $a): int => $a->monthlyValue());
        $customer->forceFill(['monthly_fee' => $mrr > 0 ? $mrr : ($agreements->isNotEmpty() ? 0 : $customer->monthly_fee)])->save();
        // The sales record of this customer follows: active agreement → müşteri; everything ended → eski müşteri.
        $active = $agreements->contains(fn (Agreement $a): bool => $a->isActive() || $a->status === 'paused');
        SalesLead::query()->where('customer_id', $customerId)->whereIn('lifecycle', ['customer', 'former'])->update(['lifecycle' => $active ? 'customer' : 'former']);
    }

    /** Monthly recurring revenue across every active agreement (₺, KDV hariç). */
    public function mrr(): int
    {
        return (int) Agreement::query()->active()->whereIn('kind', ['monthly', 'term', 'partnership'])->sum('fee');
    }

    /** Unticked months past their day, oldest first, with brand and agreement. */
    public function overdue(): Collection
    {
        foreach (Agreement::query()->active()->get() as $agreement) {
            $this->ensurePayments($agreement);
        }

        return AgreementPayment::query()->with(['agreement.brand:id,name,customer_id'])->whereNull('paid_on')
            ->where('due_on', '<', CarbonImmutable::now(self::TZ)->startOfDay()->toDateString())
            ->whereHas('agreement', fn ($q) => $q->where('status', 'active'))->orderBy('due_on')->get();
    }

    /** The money won this month: every opportunity won in the month, as its amount (monthly ones count once). */
    public function wonThisMonth(): int
    {
        $from = CarbonImmutable::now(self::TZ)->startOfMonth()->utc();

        return (int) SalesOpportunity::query()->where('status', 'won')->where('won_at', '>=', $from)->sum('amount');
    }

    /**
     * Satış Düzeni 1.9: this month's wins split by how they are paid, so a one-time job is never read as monthly income.
     *
     * @return array{monthly: int, one_time: int}
     */
    public function wonThisMonthSplit(): array
    {
        $from = CarbonImmutable::now(self::TZ)->startOfMonth()->utc();
        $won = fn () => SalesOpportunity::query()->where('status', 'won')->where('won_at', '>=', $from);

        return ['monthly' => (int) $won()->whereIn('billing', ['monthly', 'term'])->sum('amount'), 'one_time' => (int) $won()->whereNotIn('billing', ['monthly', 'term'])->sum('amount')];
    }

    /**
     * What a brand's card shows: the active agreement, the last months' ticks, what is late, what ends soon.
     *
     * @return array{agreement: ?Agreement, payments: Collection<int, AgreementPayment>, one_time: Collection<int, Agreement>, overdue: int, late_days: int, next_due: ?CarbonImmutable, risks: list<array{key: string, label: string}>}
     */
    public function summary(Brand $brand): array
    {
        $agreement = Agreement::query()->where('brand_id', $brand->id)->active()->whereNotIn('kind', self::BESIDE_KINDS)->orderByDesc('id')->first()
            ?? Agreement::query()->where('brand_id', $brand->id)->active()->orderByDesc('id')->first()
            ?? Agreement::query()->where('brand_id', $brand->id)->orderByDesc('id')->first();
        $oneTime = Agreement::query()->where('brand_id', $brand->id)->whereIn('kind', self::BESIDE_KINDS)->where('id', '!=', (int) $agreement?->id)->orderByDesc('id')->limit(5)->get();
        foreach ($oneTime->concat([$agreement])->filter() as $each) {
            $this->ensurePayments($each);
        }
        $payments = $agreement !== null ? $agreement->payments()->orderByDesc('period')->limit(6)->get()->reverse()->values() : collect();
        $overdue = $payments->filter(fn (AgreementPayment $p): bool => $p->isOverdue())
            ->concat($oneTime->flatMap(fn (Agreement $a) => $a->payments()->get())->filter(fn (AgreementPayment $p): bool => $p->isOverdue()));
        $risks = [];
        if ($agreement === null && $brand->customer?->status?->value === 'active') {
            $risks[] = ['key' => 'anlasma_yok', 'label' => 'Anlaşma girilmedi'];
        }
        if ($overdue->isNotEmpty()) {
            $risks[] = ['key' => 'odeme_gecikti', 'label' => $overdue->count() > 1 ? $overdue->count().' ay ödeme gecikti' : 'Ödeme '.$overdue->first()->daysLate().' gün gecikti'];
        }
        $today = CarbonImmutable::now(self::TZ)->startOfDay();
        if ($agreement?->isActive() && $agreement->ends_on !== null && $agreement->ends_on->lte($today->addDays(self::ENDING_SOON_DAYS))) {
            $risks[] = ['key' => 'donem_bitiyor', 'label' => 'Dönem '.$agreement->ends_on->locale('tr')->isoFormat('D MMMM').' bitiyor'];
        }
        if ($agreement !== null && ! $agreement->isActive()) {
            $risks[] = ['key' => 'anlasma_'.$agreement->status, 'label' => 'Anlaşma '.mb_strtolower($agreement->statusLabel())];
        }
        $nextDue = null;
        if ($agreement?->isActive() && $agreement->kind !== 'one_time' && (int) $agreement->fee > 0) {
            $month = $today->startOfMonth();
            $nextDue = $agreement->dueIn($month)->gte($today) ? $agreement->dueIn($month) : $agreement->dueIn($month->addMonth());
            if ($agreement->ends_on !== null && $nextDue->gt($agreement->ends_on)) {
                $nextDue = null;
            }
        }

        return ['agreement' => $agreement, 'payments' => $payments, 'one_time' => $oneTime->values(), 'overdue' => $overdue->count(), 'late_days' => (int) $overdue->max(fn (AgreementPayment $p): int => $p->daysLate()), 'next_due' => $nextDue, 'risks' => $risks];
    }

    /**
     * @return array{kind: string, fee: int, term_months: ?int, starts_on: string, due_day: int, services: ?array<int, string>, note: ?string,
     *     title: ?string, party: ?string, party_phone: ?string, tax_no: ?string, plan: string, total: ?int, installments: list<array{due_on: string, amount: int, label: ?string}>}
     */
    private function clean(array $data, bool $brandless = false, bool $new = true): array
    {
        $kind = (string) ($data['kind'] ?? 'monthly');
        $plan = (string) ($data['plan'] ?? 'auto') === 'schedule' ? 'schedule' : 'auto';
        $installments = $plan === 'schedule' && ($new || isset($data['installments'])) ? $this->cleanInstallments((array) ($data['installments'] ?? [])) : [];
        $fee = (int) preg_replace('/\D+/', '', (string) ($data['fee'] ?? ''));
        $total = (int) preg_replace('/\D+/', '', (string) ($data['total'] ?? ''));
        if ($plan === 'schedule' && $installments !== []) {
            $total = (int) array_sum(array_column($installments, 'amount'));
        }
        if ($plan === 'schedule' && $fee <= 0 && $total > 0) {
            // A taksit plan typed only as dates and amounts: the fee is the whole (one-time) or the month's share (term).
            $months = max(1, (int) ($data['term_months'] ?? 1));
            $fee = $kind === 'term' ? (int) round($total / $months) : $total;
        }
        $term = isset($data['term_months']) && (string) $data['term_months'] !== '' ? (int) $data['term_months'] : null;
        $startsOn = trim((string) ($data['starts_on'] ?? ''));
        $dueDay = (int) ($data['due_day'] ?? 1);
        $errors = [];
        if (! isset(Agreement::KINDS[$kind])) {
            $errors['kind'] = 'Anlaşma türünü seç (aylık, dönemlik, tek seferlik).';
        }
        if (($fee <= 0 && $kind !== 'partnership') || $fee > 99_999_999) {
            $errors['fee'] = 'Ücreti yaz (₺, KDV hariç).';
        }
        if ($kind === 'term' && ($term === null || $term < 1 || $term > 36)) {
            $errors['term_months'] = 'Dönem kaç ay? (1-36)';
        }
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $startsOn) !== 1) {
            $errors['starts_on'] = 'Başlangıç tarihini seç.';
        }
        if ($dueDay < 1 || $dueDay > 28) {
            $errors['due_day'] = 'Ödeme günü 1-28 arasında olmalı.';
        }
        $party = trim((string) ($data['party'] ?? ''));
        if ($brandless && $party === '') {
            $errors['party'] = 'Kimden alacağını yaz (kişi ya da işletme adı).';
        }
        if ($plan === 'schedule' && $new && $installments === []) {
            $errors['installments'] = 'En az bir taksit gir (tarih ve tutar).';
        }
        if ($plan === 'schedule' && in_array($kind, ['monthly', 'yearly', 'partnership'], true)) {
            $errors['plan'] = 'Taksit planı dönemlik ya da tek seferlik işler içindir.';
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
        $short = fn (string $key, int $max): ?string => ($v = trim((string) ($data[$key] ?? ''))) !== '' ? mb_substr($v, 0, $max) : null;
        $taxNo = preg_replace('/\D+/', '', (string) ($data['tax_no'] ?? ''));
        $services = array_values(array_filter(array_map('strval', (array) ($data['services'] ?? [])), fn (string $s): bool => isset(SalesLead::SERVICES[$s])));
        $note = trim((string) ($data['note'] ?? ''));

        return ['kind' => $kind, 'fee' => $fee, 'term_months' => $kind === 'term' ? $term : null, 'starts_on' => $startsOn, 'due_day' => $dueDay,
            'services' => $services !== [] ? $services : null, 'note' => $note !== '' ? mb_substr($note, 0, 2000) : null,
            'title' => $short('title', 160), 'party' => $party !== '' ? mb_substr($party, 0, 160) : null, 'party_phone' => $short('party_phone', 30),
            'tax_no' => in_array(strlen((string) $taxNo), [10, 11], true) ? $taxNo : null, 'plan' => $plan, 'total' => $plan === 'schedule' && $total > 0 ? $total : null,
            'installments' => $installments, 'no_invoice' => filter_var($data['no_invoice'] ?? false, FILTER_VALIDATE_BOOLEAN)];
    }

    /**
     * @param  array<int, mixed>  $rows
     * @return list<array{due_on: string, amount: int, label: ?string}>
     */
    private function cleanInstallments(array $rows): array
    {
        $out = [];
        foreach ($rows as $i => $row) {
            $due = trim((string) data_get($row, 'due_on', ''));
            $amount = (int) preg_replace('/\D+/', '', (string) data_get($row, 'amount', ''));
            if ($due === '' && $amount === 0) {
                continue; // an empty line of the form
            }
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $due) !== 1 || $amount <= 0 || $amount > 99_999_999) {
                throw ValidationException::withMessages(['installments' => ((int) $i + 1).'. taksitin tarihini ve tutarını yaz.']);
            }
            $label = trim((string) data_get($row, 'label', ''));
            $out[] = ['due_on' => $due, 'amount' => $amount, 'label' => $label !== '' ? mb_substr($label, 0, 80) : null];
        }
        usort($out, fn (array $a, array $b): int => $a['due_on'] <=> $b['due_on']);
        if (count($out) > 60) {
            throw ValidationException::withMessages(['installments' => 'En fazla 60 taksit girilebilir.']);
        }

        return $out;
    }

    private static function endsOn(string $kind, CarbonImmutable $startsOn, ?int $termMonths): ?string
    {
        return match ($kind) {
            'term' => $startsOn->addMonths((int) $termMonths)->subDay()->toDateString(),
            default => null,
        };
    }

    /** Unpaid rows after the agreement's end go; what was paid stays as history. */
    private function trimPayments(Agreement $agreement): void
    {
        if ($agreement->ends_on === null) {
            return;
        }
        $agreement->payments()->whereNull('paid_on')->where('period', '>', CarbonImmutable::instance($agreement->ends_on)->format('Y-m'))->delete();
    }
}
