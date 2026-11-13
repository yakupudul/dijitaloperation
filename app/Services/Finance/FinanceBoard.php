<?php

namespace App\Services\Finance;

use App\Models\Agreement;
use App\Models\AgreementPayment;
use App\Models\FinanceDocument;
use App\Models\SalesLead;
use App\Services\AiTasks\AiWorkers;
use App\Services\Sales\Agreements;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * What the Finans screens show, read from the agreements, their payment rows and the finance documents; nothing is
 * stored here. Bu ay: who owes what this month and what is late from before, what was spent, which papers are
 * missing, what is coming. Zam: how long each fee has stood and what inflation made of it. Abonelikler: the sellers
 * that send a paper again and again, with the next day and a changed price.
 */
final class FinanceBoard
{
    public const string TZ = Agreements::TZ;

    public function __construct(private readonly Agreements $agreements, private readonly FinanceSettings $settings) {}

    /**
     * @return array{month: CarbonImmutable, rows: Collection<int, array<string, mixed>>, late: Collection<int, array<string, mixed>>,
     *     totals: array{expected: int, received: int, open: int, late: int}, spend: array<string, mixed>, checks: array<string, Collection>, upcoming: list<array<string, mixed>>,
     *     receipts: Collection<int, FinanceDocument>, fresh: int}
     */
    public function month(CarbonImmutable $month): array
    {
        $today = CarbonImmutable::now(self::TZ)->startOfDay();
        $from = $month->startOfMonth();
        $to = $month->endOfMonth();
        $isFuture = $from->gt($today);
        $active = Agreement::query()->with('brand:id,name,customer_id')->whereIn('status', ['active', 'paused'])->get();
        if (! $isFuture) {
            foreach ($active->where('status', 'active') as $agreement) {
                $this->agreements->ensurePayments($agreement);
            }
        }
        $stored = AgreementPayment::query()->with('agreement.brand:id,name,customer_id')
            ->whereDate('due_on', '>=', $from->toDateString())->whereDate('due_on', '<=', $to->toDateString())->orderBy('due_on')->orderBy('id')->get()
            ->filter(fn (AgreementPayment $p): bool => $p->agreement !== null && ($p->agreement->status !== 'ended' || $p->received() > 0 || $p->agreement->kind === 'one_time' || $p->agreement->isSchedule()));
        $invoiced = FinanceDocument::query()->where('direction', FinanceDocument::OUT)->where('status', '!=', 'ignored')->whereIn('payment_id', $stored->pluck('id'))->get()->groupBy('payment_id');
        $rows = $stored->map(fn (AgreementPayment $p): array => $this->row($p, $today, $invoiced->get($p->id)))->values();
        // A month that has not begun: what the running agreements will ask for, shown without being written.
        if ($from->gt($today->startOfMonth())) {
            $have = $stored->pluck('agreement_id')->flip();
            foreach ($active->where('status', 'active') as $agreement) {
                if (! isset($have[$agreement->id]) && $this->billsIn($agreement, $from) && (int) $agreement->fee > 0) {
                    $rows->push(['id' => null, 'agreement' => $agreement, 'name' => $agreement->name(), 'what' => $this->what($agreement), 'title' => $from->locale('tr')->isoFormat('MMMM YYYY'),
                        'amount' => (int) $agreement->fee, 'received' => 0, 'open' => (int) $agreement->fee, 'due' => $agreement->dueIn($from), 'state' => 'coming', 'days_late' => 0, 'invoices' => collect(), 'note' => null]);
                }
            }
        }
        $late = AgreementPayment::query()->with('agreement.brand:id,name,customer_id')->whereNull('paid_on')->whereDate('due_on', '<', min($from, $today)->toDateString())
            ->whereHas('agreement', fn ($q) => $q->whereIn('status', ['active', 'paused'])->orWhere('plan', 'schedule')->orWhere('kind', 'one_time'))->orderBy('due_on')->get()
            ->filter(fn (AgreementPayment $p): bool => $p->open() > 0)->map(fn (AgreementPayment $p): array => $this->row($p, $today, null))->values();
        $totals = [
            'expected' => (int) $rows->sum('amount'), 'received' => (int) $rows->sum('received'), 'open' => (int) $rows->sum('open'),
            'late' => (int) $late->sum('open') + (int) $rows->where('state', 'late')->sum('open'),
        ];

        return [
            'month' => $from, 'rows' => $rows->sortBy(fn (array $r): string => ['late' => '0', 'partial' => '1', 'due' => '2', 'coming' => '3', 'paid' => '4'][$r['state']].$r['due']->toDateString())->values(),
            'late' => $late, 'totals' => $totals, 'spend' => $this->spend($from), 'checks' => $this->checks($stored, $today),
            'upcoming' => $isFuture ? [] : $this->upcoming($today, $active),
            'receipts' => FinanceDocument::query()->with('agreement.brand:id,name', 'payment')->where('direction', FinanceDocument::RECEIPT)->where('status', 'new')->orderByDesc('id')->limit(20)->get(),
            'fresh' => FinanceDocument::query()->where('status', 'new')->where('direction', '!=', FinanceDocument::RECEIPT)->count(),
        ];
    }

    /**
     * Every job, running first: what was agreed, how it is paid, what came in and what is left.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function jobs(string $show = 'active'): Collection
    {
        $today = CarbonImmutable::now(self::TZ)->startOfDay();
        $query = Agreement::query()->with('brand:id,name,customer_id', 'payments');
        $show === 'ended' ? $query->where('status', 'ended') : $query->whereIn('status', ['active', 'paused']);

        return $query->orderByDesc('id')->limit(300)->get()->map(function (Agreement $a) use ($today): array {
            $open = $a->payments->filter(fn (AgreementPayment $p): bool => $p->paid_on === null);
            $overdue = $open->filter(fn (AgreementPayment $p): bool => $p->isOverdue());
            $next = $open->sortBy('due_on')->first();

            return [
                'agreement' => $a, 'name' => $a->name(), 'what' => $this->what($a), 'whole' => $a->wholePrice(), 'received' => $a->received(), 'remaining' => $a->remaining(),
                'overdue' => (int) $overdue->sum(fn (AgreementPayment $p): int => $p->open()), 'overdue_count' => $overdue->count(),
                'next' => $next, 'ends_in' => $a->ends_on !== null && $a->isActive() && $a->kind === 'term' ? (int) $today->diffInDays(CarbonImmutable::parse($a->ends_on->toDateString()), false) : null,
                'sort' => ($overdue->isNotEmpty() ? '0' : '1').mb_strtolower($a->name()),
            ];
        })->sortBy('sort')->values();
    }

    /**
     * Zam görünümü: every running fee, since when it stands, what the same money is worth today and what it would have
     * to be to buy the same. The oldest fee first.
     *
     * @return array{rows: Collection<int, array<string, mixed>>, inflation: float, monthly: int}
     */
    public function raises(): array
    {
        $today = CarbonImmutable::now(self::TZ)->startOfDay();
        $rate = (float) $this->settings->get('inflation') / 100;
        $rows = Agreement::query()->with('brand:id,name,customer_id')->active()->whereIn('kind', ['monthly', 'term', 'yearly'])->where('fee', '>', 0)->get()
            ->map(function (Agreement $a) use ($today, $rate): array {
                $since = $a->feeSince() ?? $today;
                $months = max(0, (int) $since->diffInMonths($today));
                $factor = $rate > 0 ? (1 + $rate) ** ($months / 12) : 1.0;
                $list = $a->kind === 'yearly' ? null : $this->settings->listPrice($a->services);

                return [
                    'agreement' => $a, 'name' => $a->name(), 'what' => $this->what($a), 'fee' => (int) $a->fee, 'unit' => $a->kind === 'yearly' ? 'yıl' : 'ay', 'since' => $since, 'months' => $months,
                    // What today's fee buys in the money of the day it was set, and the fee that would buy the same today.
                    'worth' => $rate > 0 && $months > 0 ? (int) round($a->fee / $factor) : null,
                    'same' => $rate > 0 && $months > 0 ? (int) (round($a->fee * $factor / 100) * 100) : null,
                    'list' => $list, 'under_list' => $list !== null && $list > (int) $a->fee ? $list - (int) $a->fee : 0,
                    'ends' => $a->kind === 'term' ? $a->ends_on : null,
                    'due' => $months >= 12 || ($a->kind === 'term' && $a->ends_on !== null && $a->ends_on->lte($today->addDays(45))),
                ];
            })->sortByDesc('months')->values();

        return ['rows' => $rows, 'inflation' => $rate * 100, 'monthly' => $this->agreements->mrr()];
    }

    /**
     * Abonelik radarı: a seller whose papers came in at least two different months is a subscription. The step between
     * the papers says monthly or yearly, the last paper's day plus the step says when the next is due, and a last
     * amount above the one before says the price went up.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function subscriptions(): Collection
    {
        $today = CarbonImmutable::now(self::TZ)->startOfDay();

        return FinanceDocument::query()->where('direction', FinanceDocument::IN)->where('status', '!=', 'ignored')->whereNotNull('vendor_key')->whereNotNull('doc_date')
            ->whereDate('doc_date', '>=', $today->subMonths(26)->toDateString())->orderBy('doc_date')->get()->groupBy('vendor_key')
            ->map(function (Collection $docs) use ($today): ?array {
                $days = $docs->map(fn (FinanceDocument $d): CarbonImmutable => CarbonImmutable::parse($d->doc_date->toDateString()));
                if ($days->map(fn (CarbonImmutable $d): string => $d->format('Y-m'))->unique()->count() < 2) {
                    return null;
                }
                $gaps = $days->sliding(2)->map(fn (Collection $pair): int => (int) $pair->first()->diffInDays($pair->last()))->filter(fn (int $g): bool => $g > 5)->sort()->values();
                if ($gaps->isEmpty()) {
                    return null;
                }
                $median = $gaps[(int) floor(($gaps->count() - 1) / 2)];
                $every = $median >= 300 ? 'yıllık' : ($median >= 80 ? '3 aylık' : 'aylık');
                $last = $docs->last();
                $next = match ($every) {
                    'yıllık' => $days->last()->addYear(), '3 aylık' => $days->last()->addMonths(3), default => $days->last()->addMonth(),
                };
                $before = $docs->slice(0, -1)->reverse()->first(fn (FinanceDocument $d): bool => $d->currency === $last->currency && $d->amount !== null);
                $up = $before !== null && $last->amount !== null && (float) $before->amount > 0 && (float) $last->amount > (float) $before->amount * 1.03
                    ? (int) round(((float) $last->amount - (float) $before->amount) * 100 / (float) $before->amount) : null;
                // No paper for two steps: it has ended (or its mails stopped coming).
                $stopped = $next->lt($today->subDays($every === 'aylık' ? 45 : 120));

                return ['key' => $last->vendor_key, 'name' => (string) $last->counterparty, 'category' => $last->categoryLabel(), 'every' => $every, 'last' => $last, 'count' => $docs->count(),
                    'next' => $stopped ? null : $next, 'soon' => ! $stopped && $next->gte($today) && $next->lte($today->addDays(7)), 'up' => $up, 'before' => $before, 'stopped' => $stopped,
                    'brand' => $last->rebill ? $last->brand : null, 'monthly_try' => $this->monthlyTry($last, $every)];
            })->filter()->sortBy(fn (array $s): string => ($s['stopped'] ? '1' : '0').mb_strtolower($s['name']))->values();
    }

    /**
     * The text to send for a row that waits: soft before and on its day, plainer the later it is. The amount is said
     * with KDV, as the payer will send it.
     */
    public function reminderText(AgreementPayment $payment): string
    {
        $agreement = $payment->agreement;
        $gross = (int) round($payment->open() * $this->settings->vatFactor());
        $money = 'KDV dahil '.number_format($gross, 0, ',', '.').' TL';
        $what = $payment->title().' '.($agreement !== null && filled($agreement->title) ? mb_strtolower((string) $agreement->title).' ' : '').'ödemesi';
        $late = $payment->daysLate();

        return match (true) {
            $late >= 10 => "Merhaba, {$what} ({$money}) {$late} gündür açık görünüyor. Bugün içinde tamamlayabilir misiniz? Bir aksaklık varsa birlikte çözelim.",
            $late >= 1 => "Merhaba, {$what} ({$money}) için küçük bir hatırlatma yapmak istedim. Uygun olduğunuzda gönderebilir misiniz? Teşekkürler.",
            default => "Merhaba, {$what} ({$money}) ".($payment->due_on?->locale('tr')->isoFormat('D MMMM') ?? 'bu hafta').' tarihinde. Bilginize, iyi çalışmalar.',
        };
    }

    /** The WhatsApp number of the payer when one is known (the job's own, else the brand's sales record). */
    public function phoneOf(?Agreement $agreement): ?string
    {
        if ($agreement === null) {
            return null;
        }
        $phone = $agreement->party_phone;
        if (blank($phone) && $agreement->brand_id !== null) {
            return SalesLead::query()->where('brand_id', $agreement->brand_id)->whereNotNull('phone')->orderByDesc('id')->first()?->waNumber();
        }
        $digits = (string) preg_replace('/\D+/', '', (string) $phone);
        $digits = str_starts_with($digits, '0') ? '9'.$digits : (strlen($digits) === 10 ? '90'.$digits : $digits);

        return strlen($digits) >= 11 ? $digits : null;
    }

    /**
     * An amount as the payer sent it → the amount without KDV the rows are kept in. When the money equals the open rows
     * with KDV it was sent with KDV; when it equals them without, it was typed without.
     *
     * @return array{net: int, with_vat: bool}
     */
    public function net(Agreement $agreement, float $amount, ?bool $withVat = null): array
    {
        $factor = $this->settings->vatFactor();
        if ($withVat === null) {
            $open = $agreement->payments()->whereNull('paid_on')->orderBy('due_on')->get()->map(fn (AgreementPayment $p): int => $p->open())->filter()->values();
            $sum = 0;
            $withVat = false;
            foreach ($open as $each) {
                $sum += $each;
                if (abs($sum - $amount) < 1) {
                    break; // exactly the rows without KDV
                }
                if (abs($sum * $factor - $amount) <= max(1, $sum * 0.005)) {
                    $withVat = true;
                    break;
                }
            }
            if (! $withVat && $open->isNotEmpty() && $amount > $open->first() && abs($open->first() * $factor - $amount) > 1 && abs($sum - $amount) >= 1) {
                // Neither fits a whole row: money above the first row is taken as sent with KDV when it divides evenly.
                $withVat = abs(round($amount / $factor) * $factor - $amount) < 0.01 && fmod(round($amount / $factor), 100) === 0.0;
            }
        }

        return ['net' => (int) round($withVat ? $amount / $factor : $amount), 'with_vat' => $withVat];
    }

    /** @return array<string, mixed> */
    private function row(AgreementPayment $p, CarbonImmutable $today, ?Collection $invoices): array
    {
        $due = CarbonImmutable::parse($p->due_on->toDateString(), self::TZ);
        $state = $p->paid_on !== null ? 'paid' : ($p->isOverdue() ? 'late' : ($p->isPartial() ? 'partial' : ($due->lte($today->addDays(3)) ? 'due' : 'coming')));

        return ['id' => $p->id, 'payment' => $p, 'agreement' => $p->agreement, 'name' => $p->agreement->name(), 'what' => $this->what($p->agreement), 'title' => $p->title(),
            'amount' => (int) $p->amount, 'received' => $p->received(), 'open' => $p->open(), 'due' => $due, 'state' => $state, 'days_late' => $p->daysLate(),
            'invoices' => $invoices ?? collect(), 'note' => $p->note];
    }

    /** "SEO + İşletme Profili, Google Ads · aylık" */
    private function what(Agreement $a): string
    {
        $services = collect((array) $a->services)->map(fn (string $s): string => SalesLead::SERVICES[$s] ?? $s)->implode(', ');

        return trim(($services !== '' ? $services.' · ' : '').mb_strtolower($a->kindLabel()).($a->isSchedule() ? ', taksitli' : ''));
    }

    private function billsIn(Agreement $a, CarbonImmutable $month): bool
    {
        if ($a->isSchedule() || $a->starts_on === null) {
            return false;
        }
        $period = $month->format('Y-m');
        $start = $a->starts_on->format('Y-m');
        if ($period < $start || ($a->ends_on !== null && $period > $a->ends_on->format('Y-m'))) {
            return false;
        }

        return match ($a->kind) {
            'one_time' => $period === $start,
            'yearly' => $month->month === $a->starts_on->month,
            'partnership' => (int) $a->fee > 0,
            default => true,
        };
    }

    /**
     * What was spent in the month, by category, in each currency and as one approximate ₺ sum (dollars at the rate kept
     * on Ayarlar › AI; other currencies are listed but not added).
     *
     * @return array{total_try: float, approx: bool, by_category: Collection, by_currency: Collection, count: int, previous_try: float, unconverted: list<string>}
     */
    private function spend(CarbonImmutable $from): array
    {
        $usd = (float) app(AiWorkers::class)->settings()['usd_try'];
        $sum = function (CarbonImmutable $month) use ($usd): array {
            $docs = FinanceDocument::query()->where('direction', FinanceDocument::IN)->where('status', '!=', 'ignored')
                ->whereDate('doc_date', '>=', $month->startOfMonth()->toDateString())->whereDate('doc_date', '<=', $month->endOfMonth()->toDateString())->get();
            $try = fn (FinanceDocument $d): ?float => match ($d->currency) {
                'TRY' => (float) $d->amount, 'USD' => (float) $d->amount * $usd, default => null,
            };

            return ['docs' => $docs, 'total' => (float) $docs->sum(fn (FinanceDocument $d): float => $try($d) ?? 0.0), 'try' => $try];
        };
        $now = $sum($from);
        $docs = $now['docs'];

        return [
            'total_try' => round($now['total'], 2), 'approx' => $docs->contains(fn (FinanceDocument $d): bool => $d->currency !== 'TRY'), 'count' => $docs->count(),
            'by_category' => $docs->groupBy(fn (FinanceDocument $d): string => $d->categoryLabel())->map(fn (Collection $g): float => (float) $g->sum(fn (FinanceDocument $d): float => $now['try']($d) ?? 0.0))->sortDesc(),
            'by_currency' => $docs->groupBy('currency')->map(fn (Collection $g): float => (float) $g->sum('amount')),
            'unconverted' => $docs->pluck('currency')->unique()->reject(fn (string $c): bool => in_array($c, ['TRY', 'USD'], true))->values()->all(),
            'previous_try' => round($sum($from->subMonth())['total'], 2),
        ];
    }

    /**
     * What was spent between two days (either end may be open: "tüm zamanlar"), as one approximate ₺ sum.
     *
     * @return array{total_try: float, approx: bool, count: int}
     */
    public function spendBetween(?string $from, ?string $to): array
    {
        $usd = (float) app(AiWorkers::class)->settings()['usd_try'];
        $docs = FinanceDocument::query()->where('direction', FinanceDocument::IN)->where('status', '!=', 'ignored')
            ->when($from !== null, fn ($q) => $q->whereDate('doc_date', '>=', $from))->when($to !== null, fn ($q) => $q->whereDate('doc_date', '<=', $to))->get(['amount', 'currency']);

        return ['total_try' => round((float) $docs->sum(fn (FinanceDocument $d): float => match ($d->currency) {
            'TRY' => (float) $d->amount, 'USD' => (float) $d->amount * $usd, default => 0.0,
        }), 2), 'approx' => $docs->contains(fn (FinanceDocument $d): bool => $d->currency !== 'TRY'), 'count' => $docs->count()];
    }

    /**
     * Çapraz kontrol: money that came with no invoice on file, and invoices on file whose money is late. The first
     * list is only made once invoices are being kept at all (else every payment would be on it).
     *
     * @param  Collection<int, AgreementPayment>  $stored
     * @return array{no_invoice: Collection, no_money: Collection}
     */
    private function checks(Collection $stored, CarbonImmutable $today): array
    {
        $keeps = FinanceDocument::query()->where('direction', FinanceDocument::OUT)->where('status', '!=', 'ignored')->exists();
        $withInvoice = FinanceDocument::query()->where('direction', FinanceDocument::OUT)->where('status', '!=', 'ignored')->whereIn('payment_id', $stored->pluck('id'))->pluck('payment_id')->flip();
        $noInvoice = $keeps ? $stored->filter(fn (AgreementPayment $p): bool => $p->received() > 0 && ! isset($withInvoice[$p->id]))->values() : collect();
        $noMoney = FinanceDocument::query()->with('payment.agreement.brand:id,name', 'agreement.brand:id,name')->where('direction', FinanceDocument::OUT)->where('status', '!=', 'ignored')
            ->whereNotNull('payment_id')->whereHas('payment', fn ($q) => $q->whereNull('paid_on')->whereDate('due_on', '<', $today->toDateString()))->orderBy('doc_date')->limit(30)->get();

        return ['no_invoice' => $noInvoice, 'no_money' => $noMoney];
    }

    /**
     * What is coming in the next weeks: a term that ends, a yearly renewal, a subscription about to renew or grown
     * dearer, an expense paid for a customer and not yet asked back.
     *
     * @param  Collection<int, Agreement>  $active
     * @return list<array{kind: string, title: string, detail: string, agreement_id: ?int, doc_id: ?int}>
     */
    private function upcoming(CarbonImmutable $today, Collection $active): array
    {
        $out = [];
        $tl = fn (int|float $v): string => number_format((float) $v, 0, ',', '.').' ₺';
        foreach ($active->where('status', 'active') as $a) {
            if ($a->kind === 'term' && $a->ends_on !== null && $a->ends_on->gte($today) && $a->ends_on->lte($today->addDays(Agreements::ENDING_SOON_DAYS))) {
                $out[] = ['kind' => 'ending', 'title' => $a->payer().' dönemi '.$a->ends_on->locale('tr')->isoFormat('D MMMM').' günü bitiyor',
                    'detail' => (int) $today->diffInDays(CarbonImmutable::parse($a->ends_on->toDateString())).' gün kaldı · '.$a->feeLabel().' · yenilemeyi şimdi konuş', 'agreement_id' => $a->id, 'doc_id' => null];
            }
        }
        $yearly = AgreementPayment::query()->with('agreement.brand:id,name')->whereNull('paid_on')->whereDate('due_on', '>=', $today->toDateString())->whereDate('due_on', '<=', $today->addDays(Agreement::YEARLY_AHEAD_DAYS)->toDateString())
            ->whereHas('agreement', fn ($q) => $q->where('kind', 'yearly')->where('status', 'active'))->orderBy('due_on')->get();
        foreach ($yearly as $p) {
            $out[] = ['kind' => 'yearly', 'title' => $p->agreement->name().' yenilemesi '.$p->due_on->locale('tr')->isoFormat('D MMMM'),
                'detail' => $tl($p->amount).' + KDV · '.(int) $today->diffInDays(CarbonImmutable::parse($p->due_on->toDateString())).' gün kaldı · şimdiden haber ver', 'agreement_id' => $p->agreement_id, 'doc_id' => null];
        }
        foreach ($this->subscriptions() as $s) {
            if ($s['soon']) {
                $out[] = ['kind' => 'renewal', 'title' => $s['name'].' '.$s['next']->locale('tr')->isoFormat('D MMMM').' günü yenilenecek',
                    'detail' => $s['every'].' · son tutar '.$s['last']->amountLabel().($s['brand'] !== null ? ' · '.$s['brand']->name.' adına ödüyorsun' : ''), 'agreement_id' => null, 'doc_id' => $s['last']->id];
            }
            if ($s['up'] !== null && $s['last']->doc_date->gte($today->subDays(45))) {
                $out[] = ['kind' => 'price', 'title' => $s['name'].' %'.$s['up'].' zamlandı',
                    'detail' => $s['before']->amountLabel().' → '.$s['last']->amountLabel().' ('.$s['last']->doc_date->locale('tr')->isoFormat('D MMMM').')', 'agreement_id' => null, 'doc_id' => $s['last']->id];
            }
        }
        $rebill = FinanceDocument::query()->with('brand:id,name')->where('direction', FinanceDocument::IN)->where('rebill', true)->whereNull('rebilled_agreement_id')->where('status', '!=', 'ignored')->orderBy('doc_date')->limit(20)->get();
        foreach ($rebill as $doc) {
            $out[] = ['kind' => 'rebill', 'title' => ($doc->brand?->name ?? 'Müşteri').' adına ödendi: '.$doc->counterparty,
                'detail' => $doc->amountLabel().' · '.$doc->doc_date?->locale('tr')->isoFormat('D MMMM YYYY').' · henüz müşteriden istenmedi', 'agreement_id' => null, 'doc_id' => $doc->id];
        }

        return $out;
    }

    private function monthlyTry(FinanceDocument $last, string $every): ?float
    {
        $usd = (float) app(AiWorkers::class)->settings()['usd_try'];
        $try = match ($last->currency) {
            'TRY' => (float) $last->amount, 'USD' => (float) $last->amount * $usd, default => null,
        };

        return $try === null ? null : round($try / ['yıllık' => 12, '3 aylık' => 3, 'aylık' => 1][$every], 2);
    }
}
