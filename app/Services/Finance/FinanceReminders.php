<?php

namespace App\Services\Finance;

use App\Models\Agreement;
use App\Models\AgreementPayment;
use App\Services\Assistant\PushNotifier;
use App\Services\Sales\Agreements;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Finans → telefon: on a row's day the phone says who is to pay what ("ödendiyse tek dokunuşla işaretle"); a row three
 * days late is said once more; a term about to end, a yearly renewal and a subscription about to renew or grown dearer
 * are each said once. One push per kind a day, never one per row.
 */
final class FinanceReminders
{
    public const int LATE_AFTER_DAYS = 3;

    public function __construct(private readonly Agreements $agreements, private readonly FinanceBoard $board, private readonly PushNotifier $push) {}

    /** @return array{due: int, late: int, coming: int} */
    public function run(?CarbonImmutable $today = null): array
    {
        $today = ($today ?? CarbonImmutable::now(Agreements::TZ))->startOfDay();
        foreach (Agreement::query()->active()->get() as $agreement) {
            $this->agreements->ensurePayments($agreement);
        }
        $tl = fn (int $v): string => number_format($v, 0, ',', '.').' ₺';
        $line = fn (AgreementPayment $p): string => $p->agreement->payer().' '.$tl($p->open());
        $open = fn () => AgreementPayment::query()->with('agreement.brand:id,name')->whereNull('paid_on')->whereHas('agreement', fn ($q) => $q->where('status', 'active')->orWhere('plan', 'schedule'));

        /** @var Collection<int, AgreementPayment> $due */
        $due = $open()->whereDate('due_on', $today->toDateString())->whereNull('reminded_on')->orderBy('id')->get()->filter(fn (AgreementPayment $p): bool => $p->open() > 0);
        if ($due->isNotEmpty()) {
            $this->push->send('finance-due:'.$today->toDateString(), 'Bugün ödeme günü: '.($due->count() === 1 ? $line($due->first()) : $due->count().' ödeme'),
                mb_substr($due->map($line)->implode(' · '), 0, 300).' · Geldiyse Finans\'ta işaretle.', 'info', route('operator.finance'), 20, true);
            AgreementPayment::query()->whereIn('id', $due->pluck('id'))->update(['reminded_on' => $today->toDateString()]);
        }
        $late = $open()->whereDate('due_on', '<=', $today->subDays(self::LATE_AFTER_DAYS)->toDateString())->whereNull('late_reminded_on')->orderBy('due_on')->get()->filter(fn (AgreementPayment $p): bool => $p->open() > 0);
        if ($late->isNotEmpty()) {
            $this->push->send('finance-late:'.$today->toDateString(), ($late->count() === 1 ? $line($late->first()).' gecikti' : $late->count().' ödeme gecikti'),
                mb_substr($late->map(fn (AgreementPayment $p): string => $line($p).' ('.$p->daysLate().' gün)')->implode(' · '), 0, 300).' · Hatırlatma metni Finans\'ta hazır.', 'warning', route('operator.finance'), 20, true);
            AgreementPayment::query()->whereIn('id', $late->pluck('id'))->update(['late_reminded_on' => $today->toDateString()]);
        }
        $coming = 0;
        foreach ($this->board->month($today)['upcoming'] as $item) {
            if ($item['kind'] === 'rebill') {
                continue; // stays on the screen; not a day's news
            }
            // Said once: the key is the thing itself, kept far longer than it stays on the list.
            $coming += $this->push->send('finance-'.$item['kind'].':'.md5($item['title']), $item['title'], $item['detail'], $item['kind'] === 'price' ? 'warning' : 'info', route('operator.finance'), 24 * 50);
        }

        return ['due' => $due->count(), 'late' => $late->count(), 'coming' => $coming];
    }
}
