<?php

namespace App\Panel\Services;

use App\Models\Agreement;
use App\Models\AgreementPayment;
use App\Models\Brand;
use App\Models\BrandSession;
use App\Models\FinanceDocument;
use App\Panel\Models\PanelBrandSetting;
use App\Panel\Models\PanelMeeting;
use App\Panel\Models\PanelMembership;
use App\Panel\Models\PanelTicket;
use App\Panel\Support\PanelContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * What the customer panels ask of the operator today, across every brand (yakup 2026-11-27, Bugün on the app
 * prototype): requests without an answer, meeting requests without a day, receipts to check at the bank, renewals
 * coming up, reports nobody opened and panels nobody enters. Counts and the agency's own records only.
 */
final class PanelDesk
{
    /** A published report unread for this many days is worth a word to the customer. */
    public const int UNREAD_DAYS = 5;

    /** A panel nobody entered for this many days. */
    public const int QUIET_DAYS = 21;

    /** A yearly job shows this many days before its day. */
    public const int RENEWAL_DAYS = 30;

    public function __construct(private readonly PanelContext $context) {}

    /**
     * @return Collection<int, array{kind: string, brand: string, brand_id: int, text: string, url: string, tone: string, when: string}>
     */
    public function rows(): Collection
    {
        $names = Brand::query()->pluck('name', 'id');
        $rows = collect();
        $add = function (string $kind, int $brandId, string $text, string $tab, string $tone, string $when, array $extra = []) use (&$rows, $names): void {
            if (isset($names[$brandId])) {
                $rows->push(['kind' => $kind, 'brand' => (string) $names[$brandId], 'brand_id' => $brandId, 'text' => $text, 'tone' => $tone, 'when' => $when,
                    'url' => route('operator.brand', ['brand' => $brandId, 'tab' => $tab] + $extra)]);
            }
        };
        $tz = 'Europe/Istanbul';
        $today = CarbonImmutable::now($tz)->startOfDay();

        foreach ($this->context->system(fn () => PanelTicket::query()->where('status', PanelTicket::OPEN)->orderBy('last_message_at')->limit(50)->get(['id', 'brand_id', 'subject', 'last_message_at'])) as $ticket) {
            $add('ticket', (int) $ticket->brand_id, 'Talep cevap bekliyor: '.$ticket->subject, 'talepler', 'red', (string) $ticket->last_message_at?->timezone($tz)->diffForHumans(), ['talep' => $ticket->id]);
        }
        foreach ($this->context->system(fn () => PanelMeeting::query()->where('status', PanelMeeting::REQUESTED)->orderBy('id')->limit(30)->get(['id', 'brand_id', 'topic', 'created_at'])) as $meeting) {
            $add('meeting', (int) $meeting->brand_id, 'Toplantı isteğine gün ver: '.$meeting->topic, 'panel', 'amber', (string) $meeting->created_at?->timezone($tz)->diffForHumans());
        }
        foreach (FinanceDocument::query()->with('agreement:id,brand_id')->where('direction', FinanceDocument::RECEIPT)->where('status', 'new')->where('source', 'panel')->orderBy('id')->limit(30)->get() as $receipt) {
            $brandId = (int) ($receipt->brand_id ?: $receipt->agreement?->brand_id);
            $add('receipt', $brandId, 'Dekont yüklendi, bankada görünce işaretle', 'finans', 'amber', (string) $receipt->created_at?->timezone($tz)->diffForHumans());
        }
        $yearly = Agreement::query()->with('payments')->where('kind', 'yearly')->where('status', 'active')->whereNotNull('brand_id')->get();
        foreach ($yearly as $agreement) {
            $next = $agreement->payments->first(fn (AgreementPayment $p): bool => $p->paid_on === null && $p->due_on !== null);
            if ($next === null) {
                continue;
            }
            $days = (int) $today->diffInDays(CarbonImmutable::parse($next->due_on->toDateString(), $tz), false);
            if ($days <= self::RENEWAL_DAYS) {
                $add('renewal', (int) $agreement->brand_id, ($agreement->title ?: 'Yıllık iş').' yenilemesi, '.PanelAccount::money($next->open()), 'finans', $days < 0 ? 'red' : 'amber',
                    $days < 0 ? abs($days).' gün geçti' : ($days === 0 ? 'bugün' : $days.' gün sonra'));
            }
        }
        foreach (BrandSession::query()->whereNotNull('panel_published_at')->whereNull('panel_read_at')->where('panel_published_at', '<', now()->subDays(self::UNREAD_DAYS))->orderByDesc('month')->limit(30)->get() as $report) {
            $add('unread', (int) $report->brand_id, $report->monthLabel().' raporu okunmadı, bir hatırlat', 'rapor', 'soft', (string) $report->panel_published_at->timezone($tz)->diffForHumans());
        }
        $enabled = PanelBrandSetting::query()->where('is_enabled', true)->whereNull('leaving_at')->pluck('brand_id');
        $logins = $this->context->system(fn () => PanelMembership::query()->with('user:id,last_login_at,created_at')->whereIn('brand_id', $enabled)->where('is_active', true)->get())->groupBy('brand_id');
        foreach ($logins as $brandId => $members) {
            $last = $members->map(fn (PanelMembership $m) => $m->user?->last_login_at)->filter()->max();
            $since = $last ?? $members->map(fn (PanelMembership $m) => $m->created_at)->filter()->min();
            if ($since !== null && $since->lt(now()->subDays(self::QUIET_DAYS))) {
                $add('quiet', (int) $brandId, $last ? 'Panele '.(int) $last->diffInDays(now()).' gündür giren yok' : 'Panele hiç girilmedi', 'panel', 'soft', '');
            }
        }

        return $rows->values();
    }
}
