<?php

namespace App\Livewire\Operator\Work;

use App\Contracts\Collection\ActivityTierReader;
use App\Models\AdvisorItem;
use App\Models\Brand;
use App\Models\DigitalAsset;
use App\Models\SeoTask;
use App\Services\Advisor\AdvisorItemActions;
use App\Services\Advisor\GoogleAds\GoogleAdsEditorExport;
use App\Services\CommandCenter\Activity\ActivitySuppression;
use App\Services\CommandCenter\CommandCenter;
use App\Services\CommandCenter\InboxAging;
use App\Services\CommandCenter\TopicCatalog;
use App\Services\SeoTasks\SeoPlanRunner;
use App\Support\Demo\DemoState;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

/**
 * Komuta merkezi: every brand's and every channel's open work as a topic → assets inbox.
 *
 * Left: topics (the kind of problem: "Bütçe / bakiye bitti", "Erişim gitti"…) under Acil / Bu hafta / Fırsatlar and a
 * collapsed "Uzun süredir devam eden"; right: the selected topic's explanation and the affected assets with bulk
 * actions; clicking a row opens a detail drawer. Closing an item here closes it in its own screen too. Danışman
 * (/ads-advisor) and SEO görevleri (/seo-tasks) are this inbox pre-filtered (AdvisorInboxPage, SeoInboxPage).
 */
#[Layout('operator.layouts.app')]
#[Title('Komuta merkezi')]
class CommandCenterPage extends Component
{
    public const array SNOOZE_DAYS = [1, 3, 7, 30];

    #[Url]
    public ?int $brand = null;

    #[Url]
    public string $area = '';

    /** Other screens link `?asset={id}` to show one digital asset's work. */
    #[Url]
    public ?int $asset = null;

    /** Legacy links (`?source=coverage`, `?source=data`) still narrow the list to one producer. */
    #[Url]
    public string $source = '';

    /** Selected topic key; "aged:" prefix for the same topic under "Uzun süredir devam eden". */
    #[Url]
    public string $topic = '';

    public ?string $openKey = null;

    public bool $showAged = false;

    /** @var list<string> */
    public array $bulkIds = [];

    /** @var list<array{id: int, title: string, reason: string}> items of the last Editor export that stay manual */
    public array $manualItems = [];

    /** One evaluation per request, shared by the action and the render that follows it. */
    private ?CommandCenter $center = null;

    /** @return array{title: string, subtitle: string, detailed_route: ?string, detailed_label: ?string} */
    protected function heading(): array
    {
        return [
            'title' => 'Komuta merkezi',
            'subtitle' => 'Bütün markaların açık işleri konuya göre: solda sorun türü, sağda etkilenen hesaplar. Aynı sorun tek başlıkta; burada kapattığınız iş kendi ekranında da kapanır.',
            'detailed_route' => null,
            'detailed_label' => null,
        ];
    }

    public function updating(string $name): void
    {
        if (in_array($name, ['brand', 'area', 'asset', 'source'], true)) {
            $this->bulkIds = [];
            $this->openKey = null;
            $this->topic = '';
        }
    }

    public function setArea(string $area): void
    {
        $this->area = isset(TopicCatalog::AREAS[$area]) ? $area : '';
        $this->updating('area');
    }

    public function clearFilter(string $name): void
    {
        match ($name) {
            'brand' => $this->brand = null,
            'asset' => $this->asset = null,
            'source' => $this->source = '',
            default => null,
        };
        $this->updating($name);
    }

    public function selectTopic(string $topic): void
    {
        $this->topic = $topic;
        $this->bulkIds = [];
        $this->openKey = null;
        if (str_starts_with($topic, 'aged:')) {
            $this->showAged = true;
        }
    }

    /** Keyboard j / k: the next / previous topic in the left list. */
    public function moveTopic(int $step): void
    {
        $order = $this->topicOrder($this->topics($this->scoped($this->center())));
        if ($order === []) {
            return;
        }
        $index = array_search($this->selectedTopic($order), $order, true);
        $next = $order[max(0, min(count($order) - 1, ($index === false ? 0 : $index) + ($step >= 0 ? 1 : -1)))];
        $this->selectTopic($next);
    }

    public function openItem(string $key): void
    {
        $this->openKey = $this->openKey === $key ? null : $key;
    }

    public function closeItem(): void
    {
        $this->openKey = null;
    }

    public function selectAll(): void
    {
        $topics = $this->topics($this->scoped($this->center()));
        $selected = $this->selectedTopic($this->topicOrder($topics));
        $this->bulkIds = $selected !== null ? $topics[$selected]['items']->pluck('key')->all() : [];
    }

    public function act(string $action, ?string $key = null, int $days = 7): void
    {
        abort_unless(in_array($action, ['done', 'snooze', 'dismiss'], true), 422);
        $user = auth()->user();
        abort_unless($user?->is_active, 403);
        $keys = $key !== null ? [$key] : $this->bulkIds;
        $count = $this->center()->act($keys, $action, $user, $days);
        if ($action === 'done') {
            $this->verifyDone($keys);
        }
        $this->bulkIds = [];
        if ($key !== null && $this->openKey === $key) {
            $this->openKey = null;
        }
        DemoState::flash(match ($action) {
            'done' => $count.' iş yapıldı olarak işaretlendi.',
            'snooze' => $count.' iş '.$days.' gün ertelendi.',
            default => $count.' iş kapatıldı.',
        });
    }

    /** Operator-approved AI copy draft for an advisor item whose rule offers one (same as the Danışman panel). */
    public function requestDraft(string $key): void
    {
        $item = $this->advisorItem($key);
        $message = $item !== null ? app(AdvisorItemActions::class)->requestDraft($item) : null;
        if ($message !== null) {
            $this->center()->refresh();
            $this->openKey = $key;
            DemoState::flash($message);
        }
    }

    /**
     * "Hesap duraklatıldı (müşteri kararı)": the client stopped this ad account on purpose. The activity reader
     * records it and the account's budget items leave the inbox; without an activity reader nothing is recorded.
     */
    public function pauseAccount(string $key): void
    {
        $user = auth()->user();
        abort_unless($user?->is_active, 403);
        $item = $this->center()->items()->firstWhere('key', $key);
        if ($item === null || ($item['asset_id'] ?? null) === null || ! ActivitySuppression::isBudgetTopic($item)) {
            return;
        }
        $recorded = app(ActivityTierReader::class)->pause((int) $item['asset_id'], $user);
        $this->center()->refresh();
        DemoState::flash($recorded
            ? ($item['asset'] ?? 'Hesap').' duraklatıldı olarak işaretlendi; bütçe uyarıları hesap yeniden harcayana kadar gösterilmez.'
            : 'Duraklatma henüz kaydedilemiyor (hesap etkinlik takibi bu kurulumda yok). Uyarıyı şimdilik "Ertele" ile susturun.');
        $this->openKey = null;
    }

    /**
     * Value loop: the selected Google Ads advisor items as a Google Ads Editor import file (same as the Danışman
     * panel). Nothing is sent to Google Ads; exported items stay open until the operator marks them done.
     */
    public function exportEditor(GoogleAdsEditorExport $export): ?StreamedResponse
    {
        $ids = collect($this->bulkIds)->filter(fn (string $key): bool => str_starts_with($key, 'advisor:'))->map(fn (string $key): int => (int) substr($key, 8))->values()->all();
        $items = AdvisorItem::query()->open()->whereIn('id', $ids)->where('channel', 'google_ads')->get();
        $result = $export->build($items);
        $this->manualItems = $result['manual'];
        if ($result['rows'] === []) {
            DemoState::flash('Seçili önerilerden Editor dosyasına çevrilebilen satır çıkmadı; aşağıdaki öneriler elle yapılacak.', 'error');

            return null;
        }
        AdvisorItem::query()->whereIn('id', $result['exported'])->update(['exported_at' => now(), 'exported_by' => auth()->id(), 'updated_at' => now()]);
        $this->center()->refresh();
        $this->bulkIds = [];
        DemoState::flash(sprintf('%d öneriden %d satırlık Google Ads Editor dosyası hazırlandı. Editor\'da Hesap → İçe aktar → Dosyadan ile yükle, değişiklikleri gözden geçirip gönder; sonra öneriyi "Yapıldı" olarak işaretle.', count($result['exported']), count($result['rows'])));
        $body = $export->file($result['rows']);

        return response()->streamDownload(static function () use ($body): void {
            echo $body;
        }, 'moxdop-google-ads-editor-'.now()->format('Ymd-His').'.csv', ['Content-Type' => 'text/csv; charset=UTF-16LE']);
    }

    public function render(): View
    {
        $center = $this->center();
        $base = $this->base($center);
        $scoped = $this->area !== '' ? $base->where('area', $this->area)->values() : $base;
        $topics = $this->topics($scoped);
        $order = $this->topicOrder($topics);
        $selectedKey = $this->selectedTopic($order);
        $selected = $selectedKey !== null ? $topics[$selectedKey] : null;
        $nav = [];
        foreach ([...array_keys(TopicCatalog::GROUPS), 'aged'] as $group) {
            $nav[$group] = collect($topics)->where('group', $group)->sortByDesc('score')->values()->all();
        }
        $selectedItems = $selected !== null ? $selected['items']->take(200)->values() : collect();
        $suppressed = $center->suppressed()
            ->when($this->brand !== null, fn (Collection $c) => $c->where('brand_id', $this->brand))
            ->when($this->asset !== null, fn (Collection $c) => $c->where('asset_id', $this->asset));
        $selectedAds = collect($this->bulkIds)->filter(fn (string $key): bool => str_starts_with($key, 'advisor:'))->isNotEmpty()
            && $base->whereIn('key', $this->bulkIds)->where('channel_key', 'google_ads')->isNotEmpty();

        return view('livewire.operator.work.command-center', [
            'flash' => DemoState::pullFlash(),
            'heading' => $this->heading(),
            'nav' => $nav,
            'selected' => $selected,
            'selectedKey' => $selectedKey,
            'selectedItems' => $selectedItems,
            'open' => $this->openKey !== null ? $base->firstWhere('key', $this->openKey) : null,
            'summary' => $center->summary($scoped) + ['aged' => $scoped->where('aged', true)->count()],
            'areaCounts' => $base->where('aged', false)->countBy('area')->all(),
            'total' => $base->where('aged', false)->count(),
            'suppressedCount' => $suppressed->count(),
            'suppressedAssets' => $suppressed->pluck('asset')->filter()->unique()->values()->all(),
            'brands' => Brand::query()->whereIn('id', $center->items()->pluck('brand_id')->filter()->unique())->orderBy('name')->pluck('name', 'id'),
            'assetName' => $this->asset !== null ? DigitalAsset::query()->whereKey($this->asset)->value('name') : null,
            'showEditorExport' => $this->area === 'ads' && $selectedAds,
            'agingDays' => app(InboxAging::class)->days(),
        ]);
    }

    private function center(): CommandCenter
    {
        return $this->center ??= app(CommandCenter::class);
    }

    /** @return Collection<int, array<string, mixed>> items after brand / asset / source, before the area filter */
    private function base(CommandCenter $center): Collection
    {
        return $center->items(['brand_id' => $this->brand, 'asset_id' => $this->asset, 'source' => $this->source]);
    }

    /** @return Collection<int, array<string, mixed>> */
    private function scoped(CommandCenter $center): Collection
    {
        $base = $this->base($center);

        return $this->area !== '' ? $base->where('area', $this->area)->values() : $base;
    }

    /**
     * Items grouped by topic; aged items form a separate "aged:" topic under "Uzun süredir devam eden".
     *
     * @param  Collection<int, array<string, mixed>>  $items
     * @return array<string, array{key: string, topic: string, label: string, group: string, area: string, explanation: string, items: Collection<int, array<string, mixed>>, count: int, assets: int, severity: string, score: float, money: float, budget: bool}>
     */
    private function topics(Collection $items): array
    {
        $rank = ['critical' => 4, 'high' => 3, 'medium' => 2, 'low' => 1];
        $out = [];
        foreach ($items->groupBy(fn (array $item): string => ($item['aged'] ? 'aged:' : '').$item['topic']) as $key => $rows) {
            $first = $rows->first();
            $definition = TopicCatalog::forItem($first);
            $rows = $rows->sortByDesc('score')->values();
            $out[(string) $key] = [
                'key' => (string) $key,
                'topic' => (string) $first['topic'],
                'label' => $definition['label'],
                'group' => $first['aged'] ? 'aged' : $definition['group'],
                'area' => $definition['area'],
                'explanation' => $definition['explanation'],
                'items' => $rows,
                'count' => $rows->count(),
                // Affected assets: the digital asset when known, else the brand, else the item itself.
                'assets' => $rows->map(fn (array $item): string => $item['asset_id'] !== null ? 'a'.$item['asset_id'] : ($item['brand_id'] !== null ? 'b'.$item['brand_id'] : 'i'.$item['key']))->unique()->count(),
                'severity' => (string) $rows->sortByDesc(fn (array $item): int => $rank[$item['severity']] ?? 0)->first()['severity'],
                'score' => (float) $rows->max('score'),
                'money' => (float) $rows->sum('money'),
                'budget' => ActivitySuppression::isBudgetTopic($first),
            ];
        }

        return $out;
    }

    /**
     * @param  array<string, array<string, mixed>>  $topics
     * @return list<string> topic keys in the order of the left list
     */
    private function topicOrder(array $topics): array
    {
        $order = [];
        foreach ([...array_keys(TopicCatalog::GROUPS), 'aged'] as $group) {
            foreach (collect($topics)->where('group', $group)->sortByDesc('score') as $topic) {
                $order[] = $topic['key'];
            }
        }

        return $order;
    }

    /** @param  list<string>  $order */
    private function selectedTopic(array $order): ?string
    {
        if ($this->topic !== '' && in_array($this->topic, $order, true)) {
            return $this->topic;
        }
        $fresh = array_values(array_filter($order, fn (string $key): bool => ! str_starts_with($key, 'aged:')));

        return $fresh[0] ?? $order[0] ?? null;
    }

    private function advisorItem(string $key): ?AdvisorItem
    {
        return str_starts_with($key, 'advisor:') ? AdvisorItem::query()->open()->find((int) substr($key, 8)) : null;
    }

    /**
     * Faz 7: "Yapıldı" on advisor items / SEO tasks re-runs the rules for each asset once (rules only, no AI), the same
     * as the Danışman and SEO görevleri panels.
     *
     * @param  list<string>  $keys
     */
    private function verifyDone(array $keys): void
    {
        if (! (bool) config('moxdop-advisor.brain.verify_on_done', true)) {
            return;
        }
        $ids = fn (string $prefix): array => collect($keys)->filter(fn (string $key): bool => str_starts_with($key, $prefix))->map(fn (string $key): int => (int) substr($key, strlen($prefix)))->values()->all();
        try {
            foreach (AdvisorItem::query()->whereIn('id', $ids('advisor:'))->get()->unique('digital_asset_id') as $item) {
                app(AdvisorItemActions::class)->verify($item, auth()->user());
            }
            foreach (SeoTask::query()->whereIn('id', $ids('seo:'))->pluck('digital_asset_id')->unique() as $siteId) {
                $site = DigitalAsset::query()->find($siteId);
                try {
                    if ($site !== null) {
                        app(SeoPlanRunner::class)->queue($site, auth()->user(), 'verify');
                    }
                } catch (ValidationException) {
                    // SEO tasks disabled or not a website: the weekly plan verifies instead.
                }
            }
        } catch (Throwable $error) {
            report($error);
        }
    }
}
