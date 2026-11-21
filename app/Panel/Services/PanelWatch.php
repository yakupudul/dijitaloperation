<?php

namespace App\Panel\Services;

use App\Models\Brand;
use App\Models\Reminder;
use App\Models\User;
use App\Panel\Models\PanelBrandSetting;
use App\Panel\Models\PanelChannel;
use App\Panel\Models\PanelMembership;
use App\Panel\Models\PanelNotification;
use App\Panel\Models\PanelPerson;
use App\Panel\Support\PanelContext;
use App\Panel\Support\PanelStages;
use App\Services\Calendar\QuickCapture;
use App\Support\Roles;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Cache;
use Throwable;

/**
 * The early signs that a brand is letting the panel go: a staff member who stopped signing in, leads nobody touched
 * for two weeks, a brand nobody opened. The brand's manager hears the bell; the agency sees the silent brand.
 */
final class PanelWatch
{
    public const int STALE_DAYS = 14;

    public const int IDLE_WORKDAYS = 3;

    public const int SILENT_DAYS = 3;

    public function __construct(private readonly PanelContext $context, private readonly PanelNotifier $notifier) {}

    /** Open leads nobody wrote on for two weeks. */
    public static function stale(Builder $people): Builder
    {
        $limit = now()->subDays(self::STALE_DAYS);

        return $people->whereIn('stage', PanelStages::open())->where(fn (Builder $q) => $q->where('last_activity_at', '<', $limit)
            ->orWhere(fn (Builder $n) => $n->whereNull('last_activity_at')->where('created_at', '<', $limit)));
    }

    /** @return list<int> open brands with users where nobody signed in for two weeks */
    public function idleBrands(): array
    {
        $limit = now()->subDays(self::STALE_DAYS);
        $idle = [];
        foreach (PanelBrandSetting::query()->where('is_enabled', true)->where('created_at', '<', $limit)->pluck('brand_id') as $brandId) {
            $members = PanelMembership::query()->with('user:id,last_login_at')->where('brand_id', $brandId)->where('is_active', true)->get();
            if ($members->isNotEmpty() && $members->every(fn (PanelMembership $m): bool => $m->user?->last_login_at === null || $m->user->last_login_at->lt($limit))) {
                $idle[] = (int) $brandId;
            }
        }

        return $idle;
    }

    /** The day that lies this many working days (Monday to Saturday) before now. */
    private function workdaysAgo(int $days): CarbonImmutable
    {
        $day = CarbonImmutable::now('Europe/Istanbul')->startOfDay();
        while ($days > 0) {
            $day = $day->subDay();
            if (! $day->isSunday()) {
                $days--;
            }
        }

        return $day->utc();
    }

    /**
     * The daily look. Each warning is told once per stretch, never repeated day after day.
     *
     * @return array{idle_users: int, summaries: int, idle_brands: int, silent_channels: int}
     */
    public function run(): array
    {
        $done = ['idle_users' => 0, 'summaries' => 0, 'idle_brands' => 0, 'silent_channels' => 0];
        $limit = $this->workdaysAgo(self::IDLE_WORKDAYS);
        $monday = now('Europe/Istanbul')->isMonday();
        $brands = PanelBrandSetting::query()->where('is_enabled', true)->where('is_read_only', false)->pluck('brand_id');
        foreach ($brands as $brandId) {
            $brandId = (int) $brandId;
            $members = PanelMembership::query()->with('user')->where('brand_id', $brandId)->where('is_active', true)->get();
            foreach ($members->where('is_manager', false) as $member) {
                $last = $member->user?->last_login_at ?? $member->created_at;
                if ($member->user === null || ! $member->user->is_active || $last === null || $last->gte($limit)) {
                    continue;
                }
                if (Cache::add('panel.watch.idle.'.$member->id, true, now()->addDays(14))) {
                    $this->notifier->brand($brandId, 'idle_user', $member->user->name.' '.self::IDLE_WORKDAYS.' iş günüdür panele girmedi', route('panel.users'), 'menu.users', [(int) $member->panel_user_id]);
                    $done['idle_users']++;
                }
            }
            if ($monday && $members->isNotEmpty() && Cache::add('panel.watch.week.'.$brandId.'.'.now('Europe/Istanbul')->format('o-W'), true, now()->addDays(8))) {
                $from = now('Europe/Istanbul')->subWeek()->startOfWeek()->utc();
                $to = now('Europe/Istanbul')->startOfWeek()->utc();
                [$new, $customers, $waiting, $stale] = $this->context->system(fn (): array => [
                    PanelPerson::query()->where('brand_id', $brandId)->where('created_at', '>=', $from)->where('created_at', '<', $to)->count(),
                    PanelPerson::query()->where('brand_id', $brandId)->where('stage', PanelStages::CUSTOMER)->where('updated_at', '>=', $from)->where('updated_at', '<', $to)->count(),
                    PanelPerson::query()->where('brand_id', $brandId)->where('stage', PanelStages::NEW)->count(),
                    self::stale(PanelPerson::query()->where('brand_id', $brandId))->count(),
                ]);
                if ($new + $customers + $waiting + $stale > 0) {
                    $text = 'Geçen hafta: '.$new.' yeni aday, '.$customers.' müşteri. '.$waiting.' aday aranmayı bekliyor'.($stale > 0 ? ', '.$stale.' aday '.self::STALE_DAYS.' gündür dokunulmadı' : '').'.';
                    $this->notifier->brand($brandId, 'weekly', $text, route('panel.performance', ['donem' => '7']), 'menu.performance');
                    $done['summaries']++;
                }
            }
        }
        // A connected channel that used to speak and has been silent for days is more often a broken link than a quiet week.
        $silent = $this->context->system(fn () => PanelChannel::query()->where('status', PanelChannel::CONNECTED)->whereIn('channel', ['whatsapp', 'instagram', 'facebook'])
            ->whereNotNull('last_event_at')->where('last_event_at', '<', now()->subDays(self::SILENT_DAYS))->get());
        foreach ($silent as $channel) {
            if (Cache::add('panel.watch.silent.'.$channel->id, true, now()->addDays(14))) {
                $label = PanelChannel::KINDS[$channel->channel][0];
                $this->notifier->brand((int) $channel->brand_id, 'channel', $label.' kanalından '.self::SILENT_DAYS.' gündür mesaj gelmiyor. Bağlantıyı kontrol ediyoruz.', route('panel.inbox', ['kanal' => $channel->channel]), 'menu.users');
                $this->tellOperator((int) $channel->brand_id, $label.' kanalı '.self::SILENT_DAYS.' gündür sessiz, bağlantıyı kontrol et');
                $done['silent_channels']++;
            }
        }
        foreach ($this->idleBrands() as $brandId) {
            if (Cache::add('panel.watch.brand.'.$brandId, true, now()->addDays(30))) {
                $this->tellOperator($brandId);
                $done['idle_brands']++;
            }
        }

        return $done;
    }

    /** A brand that went silent is the agency's call to make: it lands on the operator's Yapılacaklar once a month. */
    private function tellOperator(int $brandId, ?string $what = null): void
    {
        try {
            $operator = User::query()->role(Roles::ADMIN)->where('is_active', true)->orderBy('id')->first();
            $brand = Brand::query()->find($brandId);
            if ($operator === null || $brand === null) {
                return;
            }
            $row = app(QuickCapture::class)->capture($operator, $brand->name.': '.($what ?? 'panele '.self::STALE_DAYS.' gündür giren yok, arayıp sor'), route('operator.settings.panel-users'));
            if ($row instanceof Reminder && (int) $row->brand_id !== (int) $brand->id) {
                $row->forceFill(['brand_id' => $brand->id, 'customer_id' => $brand->customer_id])->save();
            }
        } catch (Throwable $exception) {
            report($exception);
        }
    }

    /** Is there a bell line of this kind the user has not read yet? Used so a screen does not repeat it. */
    public static function unread(int $brandId, int $userId, string $type): bool
    {
        return PanelNotification::query()->where('brand_id', $brandId)->where('panel_user_id', $userId)->where('type', $type)->whereNull('read_at')->exists();
    }
}
