<?php

namespace App\Services\CommandCenter;

use App\Enums\AdvisorItemStatus;
use App\Enums\Observability\OperationalAlertState;
use App\Enums\SeoTaskStatus;
use App\Enums\SeoTaskType;
use App\Models\AdvisorItem;
use App\Models\AssetAlert;
use App\Models\BrainProposal;
use App\Models\BrandSetupProposal;
use App\Models\ComplianceFinding;
use App\Models\Observability\OperationalAlert;
use App\Models\SeoTask;
use App\Models\User;
use App\Services\Advisor\AdvisorChannels;
use App\Services\CommandCenter\Activity\ActivitySuppression;
use App\Services\Observability\OperationalAlertExplainer;
use App\Services\Operator\OperatorPortfolioPresenter;
use App\Support\ServiceScope;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Komuta merkezi: one list of everything the operator should act on, across every brand and channel.
 *
 * Every producer (SEO tasks, channel advisors, alerts, Brain recommendations, site fixes, compliance, leads, system
 * alerts, waiting approvals) is read into one item shape. The same problem is shown once (an alert hides the advisor
 * item that says the same thing; ready site fixes hide the SEO task they fix). Items are ranked by one score:
 * severity base (critical 1000, high 700, medium 400, low 150) plus measured impact (money at stake, clicks), so
 * a TL-weighted Ads problem and an SEO page with traffic are comparable. Actions (done, snooze, dismiss) go back to
 * the item's own source, so closing it here closes it everywhere.
 */
final class CommandCenter
{
    public const array SOURCES = [
        'alert' => 'Uyarı', 'advisor' => 'Danışman', 'seo' => 'SEO', 'site_fix' => 'Site düzeltmesi', 'brain' => 'Beyin önerisi',
        'compliance' => 'Uyum', 'lead' => 'Lead', 'system' => 'Sistem', 'approval' => 'Onay bekliyor', 'coverage' => 'Kurulum eksiği',
        'calendar' => 'İçerik takvimi', 'client_approval' => 'Müşteri onayı', 'followup' => 'Takip', 'invoice' => 'Tahsilat', 'commitment' => 'Taahhüt', 'task' => 'Görev',
        'live' => 'Canlı doğrulama', 'data' => 'Veri şüpheli', 'lead_outcome' => 'Lead sonucu', 'gbp' => 'İşletme Profili',
    ];

    private const array SEVERITY_BASE = ['critical' => 1000, 'high' => 700, 'medium' => 400, 'low' => 150];

    /** Advisor rules that say the same thing as an open alert of this kind on the same asset. */
    private const array ALERT_COVERS_RULES = [
        'conversions_stopped' => ['primary-no-signal', 'no-primary-conversion'],
        'spend_spike' => ['performance-anomaly', 'daily-anomaly'],
        'delivery_stopped' => ['spend-no-results'],
        'search_traffic_drop' => ['change-impact'],
        'bad_review_unanswered' => ['gbp-reviews-unanswered'],
    ];

    /** SEO rules a ready WordPress site fix of this type repairs. */
    private const array FIX_COVERS_RULES = [
        'seo_title' => ['title-missing'], 'seo_description' => ['meta-missing'], 'alt_text' => ['alt-missing'],
        'canonical' => ['canonical-conflict'], 'noindex' => ['site-noindex'],
    ];

    /** @var list<class-string<CommandCenterSource>> producers beyond the built-in readers */
    public const array EXTRA_SOURCES = [CoverageSource::class, CalendarSource::class, AgencySource::class, TaskSource::class, VerificationSource::class, LeadOutcomeSource::class, GbpSource::class];

    public function __construct(private readonly AdvisorChannels $channels) {}

    /** @var array{items: Collection<int, array<string, mixed>>, suppressed: Collection<int, array<string, mixed>>}|null */
    private ?array $evaluated = null;

    /**
     * Open items across every producer. Each item carries its topic (TopicCatalog), `since` / `aged` (InboxAging);
     * budget items of paused / dormant ad accounts are left out (see suppressed()).
     *
     * @param  array{brand_id?: ?int, source?: ?string, severity?: ?string, area?: ?string, asset_id?: ?int, topic?: ?string, aged?: ?bool}  $filters
     * @return Collection<int, array<string, mixed>>
     */
    public function items(array $filters = []): Collection
    {
        $has = fn (string $name): bool => ($filters[$name] ?? null) !== null && $filters[$name] !== '';

        return $this->evaluate()['items']
            ->when($has('brand_id'), fn (Collection $c) => $c->where('brand_id', (int) $filters['brand_id']))
            ->when($has('source'), fn (Collection $c) => $c->where('source', $filters['source']))
            ->when($has('severity'), fn (Collection $c) => $c->where('severity', $filters['severity']))
            ->when($has('area'), fn (Collection $c) => $c->where('area', $filters['area']))
            ->when($has('asset_id'), fn (Collection $c) => $c->where('asset_id', (int) $filters['asset_id']))
            ->when($has('topic'), fn (Collection $c) => $c->where('topic', $filters['topic']))
            ->when(is_bool($filters['aged'] ?? null), fn (Collection $c) => $c->where('aged', $filters['aged']))
            ->values();
    }

    /**
     * Budget / spend items hidden because their ad account is dormant or paused on the client's decision.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function suppressed(): Collection
    {
        return $this->evaluate()['suppressed'];
    }

    /** Forgets the evaluated list (after an action changed the sources). */
    public function refresh(): void
    {
        $this->evaluated = null;
    }

    /** @return array{items: Collection<int, array<string, mixed>>, suppressed: Collection<int, array<string, mixed>>} */
    private function evaluate(): array
    {
        if ($this->evaluated !== null) {
            return $this->evaluated;
        }
        $complete = true;
        $items = collect();
        foreach ([
            fn (): Collection => $this->alerts(),
            fn (): Collection => $this->advisor(),
            fn (): Collection => $this->seo(),
            fn (): Collection => $this->siteFixes(),
            fn (): Collection => $this->brain(),
            fn (): Collection => $this->compliance(),
            fn (): Collection => $this->leads(),
            fn (): Collection => $this->system(),
            fn (): Collection => $this->approvals(),
        ] as $reader) {
            try {
                $items = $items->merge($reader());
            } catch (Throwable $error) {
                report($error);
                $complete = false;
            }
        }
        foreach (self::EXTRA_SOURCES as $source) {
            try {
                $items = $items->merge(app($source)->items());
            } catch (Throwable $error) {
                report($error);
                $complete = false;
            }
        }

        // Service scope: nothing about a brandless asset or a passive customer reaches the inbox (agency-level items stay).
        $scope = app(ServiceScope::class);
        $items = $items->filter(fn (array $item): bool => self::inServiceScope($item, $scope))->values();
        $items = $this->dedupe($items);
        $snoozed = $this->snoozedKeys();
        $items = $items->reject(fn (array $item): bool => isset($snoozed[$item['key']]))->map(function (array $item): array {
            $topic = TopicCatalog::forItem($item);

            return $item + ['topic' => $topic['key'], 'topic_label' => $topic['label'], 'group' => $topic['group'], 'area' => $topic['area']];
        })->values();
        $items = app(InboxAging::class)->track($items, $complete);
        [$visible, $suppressed] = app(ActivitySuppression::class)->split($items);

        return $this->evaluated = ['items' => $visible->sortByDesc('score')->values(), 'suppressed' => $suppressed];
    }

    /**
     * The first items with at most $perBrand per brand, so one noisy brand does not fill the dashboard.
     *
     * @return list<array<string, mixed>>
     */
    public function top(int $limit = 8, int $perBrand = 2): array
    {
        $out = [];
        $count = [];
        foreach ($this->items(['aged' => false]) as $item) {
            $key = (string) ($item['brand_id'] ?? 0);
            if (($count[$key] ?? 0) >= $perBrand) {
                continue;
            }
            $count[$key] = ($count[$key] ?? 0) + 1;
            $out[] = $item;
            if (count($out) >= $limit) {
                break;
            }
        }

        return $out;
    }

    /**
     * Counts for badges: aged items ("Uzun süredir devam eden") are not counted.
     *
     * @return array{total: int, critical: int, money: float, clicks: float, brands: int}
     */
    public function summary(?Collection $items = null): array
    {
        $items = ($items ?? $this->items())->where('aged', '!=', true);

        return [
            'total' => $items->count(),
            'critical' => $items->whereIn('severity', ['critical', 'high'])->count(),
            'money' => (float) $items->sum('money'),
            'clicks' => (float) $items->sum('clicks'),
            'brands' => $items->pluck('brand_id')->filter()->unique()->count(),
        ];
    }

    /**
     * Applies one action to items. Done / dismiss go to the item's own source; snooze uses the source's snooze where
     * it has one (SEO, advisor, alerts), otherwise the central snooze table.
     *
     * @param  list<string>  $keys
     */
    public function act(array $keys, string $action, User $user, int $days = 7): int
    {
        $days = max(1, min(180, $days));
        $count = 0;
        foreach (array_unique($keys) as $key) {
            [$source, $id] = array_pad(explode(':', (string) $key, 2), 2, '');
            if ($id === '' || ! isset(self::SOURCES[$source])) {
                continue;
            }
            $count += $this->actOne($source, $id, $action, $user, $days) ? 1 : 0;
        }
        $this->refresh();

        return $count;
    }

    private function actOne(string $source, string $id, string $action, User $user, int $days): bool
    {
        $until = now()->addDays($days);
        switch ($source) {
            case 'seo':
                $task = SeoTask::query()->open()->find((int) $id);
                if ($task === null) {
                    return false;
                }
                $task->forceFill(match ($action) {
                    'done' => ['status' => SeoTaskStatus::Done->value, 'resolved_at' => now(), 'resolved_by' => $user->id, 'snoozed_until' => null],
                    'snooze' => ['status' => SeoTaskStatus::Skipped->value, 'resolved_at' => now(), 'resolved_by' => $user->id, 'snoozed_until' => $until],
                    default => ['status' => SeoTaskStatus::Skipped->value, 'resolved_at' => now(), 'resolved_by' => $user->id, 'snoozed_until' => null],
                })->save();

                return true;
            case 'advisor':
                $item = AdvisorItem::query()->open()->find((int) $id);
                if ($item === null) {
                    return false;
                }
                $item->forceFill(match ($action) {
                    'done' => ['status' => AdvisorItemStatus::Done->value, 'resolved_at' => now(), 'resolved_by' => $user->id, 'snoozed_until' => null],
                    'snooze' => ['status' => AdvisorItemStatus::Skipped->value, 'resolved_at' => now(), 'resolved_by' => $user->id, 'snoozed_until' => $until],
                    default => ['status' => AdvisorItemStatus::Skipped->value, 'resolved_at' => now(), 'resolved_by' => $user->id, 'snoozed_until' => null],
                })->save();

                return true;
            case 'alert':
                $alert = AssetAlert::query()->open()->find((int) $id);
                if ($alert === null) {
                    return false;
                }
                // An alert closes itself when the condition clears; "done"/"dismiss" silence it until the next scan finds it again.
                $alert->forceFill($action === 'snooze' ? ['snoozed_until' => $until, 'snoozed_by' => $user->id] : ['resolved_at' => now()])->save();

                return true;
            case 'brain':
                if ($action === 'snooze') {
                    return $this->snooze($source.':'.$id, $until, $user);
                }

                return DB::table('brain_recommendations')->where('id', (int) $id)->where('status', 'open')
                    ->update(['status' => $action === 'done' ? 'done' : 'dismissed', 'resolved_by' => $user->id, 'resolved_at' => now(), 'updated_at' => now()]) > 0;
            case 'compliance':
                if ($action === 'snooze') {
                    return $this->snooze($source.':'.$id, $until, $user);
                }

                return ComplianceFinding::query()->whereKey((int) $id)->where('status', ComplianceFinding::STATUS_OPEN)
                    ->update(['status' => $action === 'done' ? ComplianceFinding::STATUS_RESOLVED : ComplianceFinding::STATUS_DISMISSED, 'status_changed_by' => $user->id, 'resolved_at' => now()]) > 0;
            case 'lead':
                if ($action === 'snooze') {
                    return $this->snooze($source.':'.$id, $until, $user);
                }

                return DB::table('agency_leads')->where('id', (int) $id)->where('status', 'new')
                    ->update(['status' => $action === 'done' ? 'contacted' : 'lost', 'handled_by' => $user->id, 'updated_at' => now()]
                    + ($action === 'done' ? ['first_response_at' => now()] : [])) > 0;
            case 'followup':
                if ($action === 'snooze') {
                    return DB::table('customer_interactions')->where('id', (int) $id)->update(['next_action_at' => $until, 'updated_at' => now()]) > 0;
                }

                return DB::table('customer_interactions')->where('id', (int) $id)->whereNull('next_action_done_at')->update(['next_action_done_at' => now(), 'updated_at' => now()]) > 0;
            case 'invoice':
                if ($action === 'snooze' || ! ctype_digit($id)) {
                    return $this->snooze($source.':'.$id, $until, $user);
                }

                return DB::table('agency_invoices')->where('id', (int) $id)->where('status', 'issued')->update(['status' => 'paid', 'paid_on' => now()->toDateString(), 'updated_at' => now()]) > 0;
            case 'task':
                if ($action === 'snooze') {
                    return DB::table('tasks')->where('id', (int) $id)->update(['due_date' => $until->toDateString(), 'updated_at' => now()]) > 0;
                }

                return DB::table('tasks')->where('id', (int) $id)->whereNotIn('status', TaskSource::CLOSED)
                    ->update(['status' => $action === 'done' ? 'completed' : 'cancelled', 'completed_at' => now(), 'updated_at' => now()]) > 0;
            case 'client_approval':
                return DB::table('client_approvals')->where('id', (int) $id)->whereNull('acknowledged_at')->update(['acknowledged_at' => now(), 'updated_at' => now()]) > 0;
            case 'calendar':
                if ($action === 'snooze') {
                    return $this->snooze($source.':'.$id, $until, $user);
                }

                return DB::table('content_calendar_items')->where('id', (int) $id)->where('channel', '!=', 'gbp_post')->whereIn('status', ['draft', 'approved'])
                    ->update(['status' => $action === 'done' ? 'published' : 'skipped', 'published_at' => $action === 'done' ? now() : null, 'updated_at' => now()]) > 0;
            default:
                // Grouped / system items are resolved in their own screen; here they can only wait.
                return $action === 'snooze' ? $this->snooze($source.':'.$id, $until, $user) : false;
        }
    }

    private function snooze(string $key, \DateTimeInterface $until, User $user): bool
    {
        DB::table('inbox_snoozes')->updateOrInsert(['item_key' => $key], ['snoozed_until' => $until, 'user_id' => $user->id, 'updated_at' => now(), 'created_at' => now()]);

        return true;
    }

    /** @return array<string, true> */
    private function snoozedKeys(): array
    {
        if (! Schema::hasTable('inbox_snoozes')) {
            return [];
        }

        return DB::table('inbox_snoozes')->where('snoozed_until', '>', now())->pluck('item_key')->mapWithKeys(fn ($k): array => [(string) $k => true])->all();
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $items
     * @return Collection<int, array<string, mixed>>
     */
    private function dedupe(Collection $items): Collection
    {
        $hide = [];
        foreach ($items as $item) {
            foreach ($item['covers'] ?? [] as $covered) {
                $hide[$covered] = $item['key'];
            }
        }

        return $items->map(function (array $item) use ($hide): array {
            $item['hidden_by'] = $hide[$item['dedupe'] ?? ''] ?? null;

            return $item;
        })->filter(fn (array $item): bool => $item['hidden_by'] === null)->values();
    }

    /**
     * An item is shown only when its asset is operational, or (no asset) its brand, or (neither) its customer is
     * active. Items with none of the three are agency-level and always shown; an overdue invoice carries no customer
     * on purpose, so money owed by a passive customer stays visible.
     *
     * @param  array<string, mixed>  $item
     */
    public static function inServiceScope(array $item, ServiceScope $scope): bool
    {
        if (($item['asset_id'] ?? null) !== null || ($item['brand_id'] ?? null) !== null) {
            return $scope->serves($item['asset_id'] ?? null, $item['brand_id'] ?? null);
        }
        if (($item['customer_id'] ?? null) !== null) {
            return $scope->isCustomerActive($item['customer_id']);
        }

        return true;
    }

    /** @return array<string, mixed> */
    public static function item(string $source, string|int $id, string $severity, string $title, array $extra = []): array
    {
        $severity = isset(self::SEVERITY_BASE[$severity]) ? $severity : 'medium';
        $money = (float) ($extra['money'] ?? 0);
        $clicks = (float) ($extra['clicks'] ?? 0);

        return array_merge([
            'key' => $source.':'.$id,
            'source' => $source,
            'source_label' => self::SOURCES[$source] ?? $source,
            'severity' => $severity,
            'title' => $title,
            'detail' => null,
            'brand_id' => null,
            'brand' => null,
            'asset' => null,
            'asset_id' => null,
            'asset_type' => null,
            'rule' => null,
            'channel' => null,
            'impact' => null,
            'url' => null,
            'actions' => ['snooze'],
            'dedupe' => null,
            'covers' => [],
            'age' => null,
            // Neden önemli / Ne yapmalısın and a one-click action, where the source can say them (system alerts).
            'why' => null,
            'action' => null,
            'link_label' => null,
            'button' => null,
            'repeat_label' => '',
        ], $extra, [
            'money' => $money,
            'clicks' => $clicks,
            // Money at stake (TL) and 90-day clicks lift an item inside its severity band, never above the next band.
            'score' => (float) ($extra['score'] ?? self::SEVERITY_BASE[$severity]) + min(280.0, $money / 10) + min(280.0, $clicks / 5),
        ]);
    }

    /** @return Collection<int, array<string, mixed>> */
    private function alerts(): Collection
    {
        return app(ServiceScope::class)->constrain(AssetAlert::query()->active())->with(['brand', 'digitalAsset'])->orderByDesc('last_detected_at')->limit(500)->get()
            ->map(fn (AssetAlert $alert): array => self::item('alert', $alert->id, (string) $alert->severity, (string) $alert->title, [
                'detail' => $alert->message,
                'brand_id' => $alert->brand_id,
                'brand' => $alert->brand?->name,
                'asset' => $alert->digitalAsset?->domain ?: $alert->digitalAsset?->name,
                'asset_id' => $alert->digital_asset_id,
                'asset_type' => $alert->digitalAsset?->type,
                'rule' => str_starts_with((string) $alert->kind, 'renewal_due_') ? 'renewal_due' : (string) $alert->kind,
                'channel' => $this->channelOf((string) $alert->digitalAsset?->type),
                // The asset's own page (where the problem is fixed); the Uyarılar list only when the asset is gone.
                'url' => $alert->digitalAsset !== null ? OperatorPortfolioPresenter::specialistUrl($alert->digitalAsset) : route('operator.alerts'),
                'actions' => ['done', 'snooze'],
                'covers' => array_map(fn (string $rule): string => 'advisor-rule:'.$alert->digital_asset_id.':'.$rule, self::ALERT_COVERS_RULES[$alert->kind] ?? []),
                'age' => $alert->first_detected_at,
                // An account that cannot spend or a site that is down outranks every recommendation.
                'score' => (self::SEVERITY_BASE[(string) $alert->severity] ?? 400) + (in_array($alert->kind, ['site_down', 'budget_account_blocked', 'delivery_stopped', 'budget_exhausted'], true) ? 300 : 0),
            ]));
    }

    /** @return Collection<int, array<string, mixed>> */
    private function advisor(): Collection
    {
        $channels = $this->channels->all();

        return app(ServiceScope::class)->constrain(AdvisorItem::query()->open())->with(['brand', 'digitalAsset'])->orderByDesc('priority_score')->limit(800)->get()
            ->map(fn (AdvisorItem $item): array => self::item('advisor', $item->id, (string) $item->severity, (string) $item->title, [
                'detail' => $item->exported_at !== null
                    ? $item->exported_at->timezone(config('app.timezone'))->format('d.m').' tarihinde dışa aktarıldı, Editor\'da yüklenmeyi bekliyor. '.$item->reason
                    : $item->reason,
                'exported_at' => $item->exported_at,
                'brand_id' => $item->brand_id,
                'brand' => $item->brand?->name,
                'asset' => $item->digitalAsset?->name,
                'asset_id' => $item->digital_asset_id,
                'asset_type' => $item->digitalAsset?->type,
                'rule' => (string) $item->rule_id,
                'channel_key' => $item->channel,
                'draftable' => isset($channels[$item->channel]) && in_array($item->rule_id, $channels[$item->channel]->draftRules(), true),
                'draft_status' => $item->draft_status,
                'channel' => ($channels[$item->channel] ?? null)?->label() ?? $item->channel,
                'impact' => $item->impact_label,
                'money' => (float) ($item->impact_amount ?? 0),
                'url' => isset($channels[$item->channel]) ? $channels[$item->channel]->assetUrl($item->digital_asset_id) : null,
                'actions' => ['done', 'snooze', 'dismiss'],
                'dedupe' => 'advisor-rule:'.$item->digital_asset_id.':'.$item->rule_id,
                'age' => $item->created_at,
                'score' => (float) $item->priority_score,
            ]));
    }

    /** @return Collection<int, array<string, mixed>> */
    private function seo(): Collection
    {
        return app(ServiceScope::class)->constrain(SeoTask::query()->open()->where('type', '!=', SeoTaskType::Question->value))->with(['brand', 'digitalAsset'])
            ->orderByDesc('priority_score')->limit(800)->get()
            ->map(fn (SeoTask $task): array => self::item('seo', $task->id, (string) $task->severity, (string) $task->title, [
                'detail' => $task->reason,
                'brand_id' => $task->brand_id,
                'brand' => $task->brand?->name,
                'asset' => $task->digitalAsset?->domain ?: $task->digitalAsset?->name,
                'asset_id' => $task->digital_asset_id,
                'asset_type' => $task->digitalAsset?->type,
                'rule' => (string) $task->rule_id,
                'channel' => 'Web / SEO · '.$task->type->label(),
                'impact' => $task->estimated_extra_clicks ? '+'.number_format((float) $task->estimated_extra_clicks, 0, ',', '.').' tık / 90 gün' : null,
                'clicks' => (float) ($task->estimated_extra_clicks ?? 0),
                'url' => route('operator.website', ['assetId' => $task->digital_asset_id, 'tab' => 'seo']),
                'actions' => ['done', 'snooze', 'dismiss'],
                'dedupe' => 'seo-rule:'.$task->digital_asset_id.':'.$task->rule_id,
                'age' => $task->created_at,
                'score' => (float) $task->priority_score,
            ]));
    }

    /** One item per website: its ready WordPress fixes (applied from the site's Düzeltmeler tab after review). */
    private function siteFixes(): Collection
    {
        if (! Schema::hasTable('site_fix_items')) {
            return collect();
        }
        $rows = DB::table('site_fix_items as f')->join('digital_assets as a', 'a.id', '=', 'f.digital_asset_id')->leftJoin('brands as b', 'b.id', '=', 'a.brand_id')
            ->where('f.status', 'open')->whereNull('a.deleted_at')->whereIn('a.id', app(ServiceScope::class)->assetIdQuery())
            ->groupBy('f.digital_asset_id', 'a.domain', 'a.name', 'a.brand_id', 'b.name')
            ->selectRaw('f.digital_asset_id, a.domain, a.name, a.brand_id, b.name as brand_name, count(*) as n, min(f.created_at) as since')->get();
        $types = DB::table('site_fix_items')->where('status', 'open')->select('digital_asset_id', 'type')->distinct()->get()->groupBy('digital_asset_id');

        return $rows->map(function (object $row) use ($types): array {
            $covers = [];
            foreach ($types->get($row->digital_asset_id, collect()) as $type) {
                foreach (self::FIX_COVERS_RULES[$type->type] ?? [] as $rule) {
                    $covers[] = 'seo-rule:'.$row->digital_asset_id.':'.$rule;
                }
            }

            return self::item('site_fix', (int) $row->digital_asset_id, (int) $row->n >= 10 ? 'high' : 'medium', sprintf('%d site düzeltmesi uygulanmaya hazır', (int) $row->n), [
                'detail' => 'Başlık, meta açıklama, alt metin, yönlendirme gibi WordPress düzeltmeleri hazır; gözden geçirip tek tıkla uygula.',
                'brand_id' => $row->brand_id !== null ? (int) $row->brand_id : null,
                'brand' => $row->brand_name,
                'asset' => $row->domain ?: $row->name,
                'asset_id' => (int) $row->digital_asset_id,
                'asset_type' => 'website',
                'rule' => 'ready',
                'channel' => 'Web sitesi',
                'url' => route('operator.website', ['assetId' => $row->digital_asset_id, 'tab' => 'fixes']),
                'covers' => $covers,
                'age' => $row->since,
            ]);
        });
    }

    /** @return Collection<int, array<string, mixed>> */
    private function brain(): Collection
    {
        if (! Schema::hasTable('brain_recommendations')) {
            return collect();
        }

        return app(ServiceScope::class)->constrain(DB::table('brain_recommendations as r')->leftJoin('brands as b', 'b.id', '=', 'r.brand_id')->where('r.status', 'open'), 'r.digital_asset_id', 'r.brand_id')
            ->orderByDesc('r.impact')->limit(400)->get(['r.*', 'b.name as brand_name'])
            ->map(fn (object $row): array => self::item('brain', (int) $row->id, $row->basis === 'validated' ? 'high' : 'medium', (string) $row->title, [
                'detail' => $row->detail,
                'brand_id' => $row->brand_id !== null ? (int) $row->brand_id : null,
                'brand' => $row->brand_name,
                'channel' => 'Hizmet Beyni',
                'impact' => $row->basis === 'validated' ? 'Etkisi kanıtlanmış yöntem' : 'Başarılı markalarda gözlendi',
                'clicks' => (float) ($row->impact ?? 0),
                'url' => route('operator.brain.recommendations', ['brand' => $row->brand_id]),
                'actions' => ['done', 'snooze', 'dismiss'],
                'age' => $row->created_at,
            ]));
    }

    /** @return Collection<int, array<string, mixed>> */
    private function compliance(): Collection
    {
        return app(ServiceScope::class)->constrain(ComplianceFinding::query()->where('status', ComplianceFinding::STATUS_OPEN))->with(['brand', 'rule'])->latest('last_seen_at')->limit(300)->get()
            ->map(fn (ComplianceFinding $finding): array => self::item('compliance', $finding->id, (string) ($finding->rule?->severity ?? 'high'), 'Uyum: '.($finding->rule?->label ?? 'kural ihlali').' — '.$finding->subject_label, [
                'detail' => $finding->excerpt,
                'brand_id' => $finding->brand_id,
                'brand' => $finding->brand?->name,
                'channel' => 'Uyum',
                'url' => route('operator.compliance'),
                'actions' => ['done', 'snooze', 'dismiss'],
                'age' => $finding->first_seen_at,
            ]));
    }

    /** @return Collection<int, array<string, mixed>> */
    private function leads(): Collection
    {
        if (! Schema::hasTable('agency_leads')) {
            return collect();
        }

        return DB::table('agency_leads')->where('status', 'new')->orderByDesc('received_at')->limit(100)->get()
            ->map(fn (object $lead): array => self::item('lead', (int) $lead->id, 'high', 'Yeni lead: '.trim(($lead->company ?: '').' '.($lead->name ?: '')), [
                'detail' => $lead->message !== null ? mb_substr((string) $lead->message, 0, 200) : null,
                'channel' => 'Satış',
                'url' => route('operator.leads'),
                'actions' => ['done', 'snooze', 'dismiss'],
                'age' => $lead->received_at,
            ]));
    }

    /**
     * System alerts, each with its plain-Turkish explanation (OperationalAlertExplainer): the affected brand / asset
     * when there is one, Ne oldu / Neden önemli / Ne yapmalısın, the exact page and a one-click action.
     *
     * @return Collection<int, array<string, mixed>>
     */
    private function system(): Collection
    {
        $explainer = app(OperationalAlertExplainer::class);

        return OperationalAlert::query()->whereIn('state', [OperationalAlertState::Open->value, OperationalAlertState::Acknowledged->value])
            ->orderByDesc('last_observed_at')->limit(50)->get()
            ->map(function (OperationalAlert $alert) use ($explainer): array {
                $message = $explainer->explain($alert);

                return self::item('system', $alert->id, match ((string) ($alert->severity->value ?? $alert->severity)) {
                    'CRITICAL' => 'critical', 'WARNING' => 'high', default => 'medium',
                }, $message->title, [
                    'detail' => $message->what,
                    'why' => $message->why,
                    'action' => $message->action,
                    'link_label' => $message->linkLabel,
                    'button' => $message->button,
                    'repeat_label' => $message->repeatLabel(),
                    'rule' => OperationalAlertExplainer::topicRule((string) $alert->rule_key),
                    'channel' => 'Sistem',
                    'brand_id' => $message->brandId,
                    'asset_id' => $message->assetId,
                    'url' => $message->linkUrl ?? route('operator.settings.system-health'),
                    'age' => $alert->first_opened_at ?? $alert->first_observed_at ?? $alert->created_at,
                ]);
            });
    }

    /** Waiting approvals: AI proposals and brand setups that are ready or stuck. */
    private function approvals(): Collection
    {
        $out = collect();
        $pending = app(ServiceScope::class)->constrain(BrainProposal::query()->where('status', BrainProposal::STATUS_PENDING), null)->count();
        if ($pending > 0) {
            $out->push(self::item('approval', 'brain', 'medium', $pending.' AI önerisi onayınızı bekliyor', [
                'detail' => 'Hesap eşleştirme, sorgu → hizmet ataması ve kümeler: onaylanınca uygulanır.',
                'rule' => 'brain',
                'channel' => 'Hizmet Beyni',
                'url' => route('operator.brain.proposals'),
            ]));
        }
        foreach (BrandSetupProposal::query()->with('brand')->whereIn('brand_id', app(ServiceScope::class)->brandIdQuery())->whereIn('status', [BrandSetupProposal::STATUS_READY, BrandSetupProposal::STATUS_QUEUED, BrandSetupProposal::STATUS_BUILDING, BrandSetupProposal::STATUS_FAILED])
            ->where('updated_at', '>=', now()->subDays(30))->get() as $proposal) {
            $stuck = $proposal->isStuck();
            if ($proposal->isPending() && ! $stuck) {
                continue;
            }
            $newer = BrandSetupProposal::query()->where('brand_id', $proposal->brand_id)->where('id', '>', $proposal->id)->exists();
            if ($newer) {
                continue;
            }
            $out->push(self::item('approval', 'setup-'.$proposal->id, $proposal->status === BrandSetupProposal::STATUS_READY ? 'medium' : 'high', match (true) {
                $stuck => 'Otomatik kur takıldı',
                $proposal->status === BrandSetupProposal::STATUS_FAILED => 'Otomatik kur başarısız',
                default => 'Otomatik kur önerileri onay bekliyor',
            }, [
                'detail' => $stuck || $proposal->status === BrandSetupProposal::STATUS_FAILED ? 'Yeniden taramak için kurulum sayfasını açın.' : 'Hesap bağlantıları ve hizmetler hazır.',
                'brand_id' => $proposal->brand_id,
                'brand' => $proposal->brand?->name,
                'rule' => 'setup',
                'channel' => 'Kurulum',
                'url' => route('operator.brand.setup', ['brand' => $proposal->brand_id]),
                'age' => $proposal->created_at,
            ]));
        }

        return $out;
    }

    private function channelOf(string $assetType): string
    {
        return match ($assetType) {
            'website' => 'Web sitesi', 'google_ads' => 'Google Ads', 'meta_ads' => 'Meta Ads', 'google_business_profile' => 'İşletme Profili',
            'ga4' => 'GA4', 'search_console' => 'Search Console', default => 'Genel',
        };
    }
}
