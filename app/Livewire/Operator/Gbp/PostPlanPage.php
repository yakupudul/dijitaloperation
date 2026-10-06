<?php

namespace App\Livewire\Operator\Gbp;

use App\Jobs\Gbp\FillGbpPostQueueJob;
use App\Models\DigitalAsset;
use App\Models\ExternalWriteAction;
use App\Models\GbpQueuedPost;
use App\Services\ExternalWrites\ExternalWriteService;
use App\Services\Gbp\Desk\GbpDesk;
use App\Services\Gbp\GbpPostQueue;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * İşletme gönderileri (`/gbp-posts`, ADR-078): the automatic Business Profile posts of every location for the next
 * 30 days on one screen, grouped by brand. Four counts on top filter the list; each location shows a 30-day strip
 * and, when days are empty, why. The Admin approves in bulk (all, a brand or a location), reads a brand's drafts one
 * under the other before approving ("Oku"), edits or skips a row and retries a post that could not be published.
 */
#[Layout('operator.layouts.app')]
#[Title('İşletme gönderileri')]
final class PostPlanPage extends Component
{
    public const FILTERS = ['draft' => 'Onay bekleyen', 'week' => 'Bu hafta yayınlanacak', 'failed' => 'Yayınlanamayan', 'low' => 'İçeriği yetmeyen'];

    /** Below this many planned days a location counts as short of content. */
    public const LOW_DAYS = 15;

    /** Drafts shown at once in the reading mode. */
    public const READ_PAGE = 40;

    #[Url(as: 'marka')]
    public ?int $brand = null;

    /** Location whose days are open. */
    #[Url(as: 'isletme')]
    public ?int $location = null;

    /** One day of the open location (from the strip), or all days. */
    #[Url(as: 'gun')]
    public ?string $day = null;

    #[Url(as: 'filtre')]
    public string $filter = '';

    /** Brand whose drafts are read one under the other. */
    #[Url(as: 'oku')]
    public ?int $read = null;

    public int $readLimit = self::READ_PAGE;

    public ?int $editing = null;

    public string $editText = '';

    public string $message = '';

    public function mount(): void
    {
        abort_unless(auth()->user()?->is_active, 403);
        if (! array_key_exists($this->filter, self::FILTERS)) {
            $this->filter = '';
        }
        if ($this->location !== null && $this->brand === null) {
            $this->brand = ($brandId = DigitalAsset::query()->whereKey($this->location)->value('brand_id')) !== null ? (int) $brandId : null;
        }
    }

    public function setFilter(string $filter): void
    {
        $this->filter = $this->filter === $filter || ! array_key_exists($filter, self::FILTERS) ? '' : $filter;
    }

    public function openBrand(int $brandId): void
    {
        $this->brand = $this->brand === $brandId ? null : $brandId;
        $this->location = null;
        $this->day = null;
        $this->editing = null;
    }

    public function open(int $assetId): void
    {
        $this->location = $this->location === $assetId && $this->day === null ? null : $assetId;
        $this->day = null;
        $this->editing = null;
    }

    public function openDay(int $assetId, string $day): void
    {
        $this->location = $assetId;
        $this->day = preg_match('/^\d{4}-\d{2}-\d{2}$/', $day) === 1 ? $day : null;
        $this->editing = null;
    }

    public function startReading(int $brandId): void
    {
        $this->read = $brandId;
        $this->readLimit = self::READ_PAGE;
        $this->editing = null;
    }

    public function stopReading(): void
    {
        $this->read = null;
        $this->editing = null;
    }

    public function readMore(): void
    {
        $this->readLimit += self::READ_PAGE;
    }

    public function approveAll(?int $assetId = null, ?int $brandId = null): void
    {
        $count = app(GbpPostQueue::class)->approveAll(auth()->user(), $assetId, $brandId);
        $this->message = $count > 0 ? $count.' gönderi onaylandı; günü gelince yayınlanır.' : 'Onay bekleyen gönderi yok.';
    }

    public function approve(int $id, GbpPostQueue $queue): void
    {
        $queue->approve(auth()->user(), [$id]);
    }

    public function skip(int $id, GbpPostQueue $queue): void
    {
        $queue->skip(auth()->user(), $this->post($id));
        $this->message = 'Atlandı; o gün bir sonraki planlamada yeniden doldurulur.';
    }

    public function retry(int $id, GbpPostQueue $queue): void
    {
        try {
            $queue->retry(auth()->user(), $this->post($id));
            $this->message = 'Bugün yeniden denenecek.';
        } catch (ValidationException $exception) {
            $this->message = (string) collect($exception->errors())->flatten()->first();
        }
    }

    public function startEdit(int $id): void
    {
        $this->editing = $id;
        $this->editText = (string) $this->post($id)->summary;
    }

    public function saveEdit(GbpPostQueue $queue): void
    {
        if ($this->editing === null) {
            return;
        }
        try {
            $queue->edit(auth()->user(), $this->post($this->editing), $this->editText);
            $this->editing = null;
        } catch (ValidationException $exception) {
            $this->addError('editText', (string) collect($exception->errors())->flatten()->first());
        }
    }

    /** Plans the empty days of every location now. */
    public function fillNow(): void
    {
        abort_unless(ExternalWriteService::allowed(auth()->user(), ExternalWriteAction::CHANNEL_GBP), 403);
        $ids = GbpPostQueue::locations()->pluck('digital_assets.id');
        foreach ($ids as $id) {
            Cache::put(FillGbpPostQueueJob::stateKey((int) $id), ['status' => 'running', 'message' => 'Hazırlanıyor…'], now()->addHour());
            FillGbpPostQueueJob::dispatch((int) $id, true);
        }
        $this->message = $ids->count().' işletme için boş günler hazırlanıyor.';
    }

    /** "İşletme Profili · Avrupadent Çiğli | İmplant | …" → "Avrupadent Çiğli". */
    public static function shortName(string $name): string
    {
        return GbpDesk::shortName($name);
    }

    public function render(GbpPostQueue $queue): View
    {
        $today = GbpPostQueue::today();
        $days = collect(range(0, GbpPostQueue::HORIZON_DAYS))->map(fn (int $i): string => $today->addDays($i)->toDateString())->all();
        $weekEnd = $today->addDays(6)->toDateString();
        $locations = GbpPostQueue::locations()->with('brand:id,name')->orderBy('digital_assets.name')
            ->get(['digital_assets.id', 'digital_assets.name', 'digital_assets.brand_id']);
        $posts = GbpQueuedPost::query()->whereIn('digital_asset_id', $locations->pluck('id'))
            ->whereBetween('publish_on', [$today->subDays(7)->toDateString(), end($days)])
            ->whereNotIn('status', [GbpQueuedPost::SKIPPED, GbpQueuedPost::EXPIRED])
            ->with('writeAction:id,status')->orderBy('id')->get(['id', 'digital_asset_id', 'publish_on', 'status', 'external_write_action_id']);
        $sources = $queue->sources($locations->pluck('brand_id')->unique()->map(fn ($id): int => (int) $id)->values()->all());
        $states = $locations->mapWithKeys(fn (DigitalAsset $l): array => [$l->id => Cache::get(FillGbpPostQueueJob::stateKey((int) $l->id))])->filter()->all();

        $info = [];
        foreach ($locations as $location) {
            $own = $posts->where('digital_asset_id', $location->id);
            $strip = [];
            $failed = 0;
            foreach ($own as $post) {
                $day = substr((string) $post->publish_on, 0, 10);
                $state = $post->status === GbpQueuedPost::FAILED || $post->writeAction?->status === 'failed' ? 'failed' : $post->status;
                $failed += $state === 'failed' ? 1 : 0;
                if ($day >= $days[0]) {
                    $strip[$day] = $state;
                }
            }
            $future = collect($strip)->filter(fn (string $s, string $d): bool => $d > $days[0]);
            $planned = $future->filter(fn (string $s): bool => in_array($s, [GbpQueuedPost::DRAFT, GbpQueuedPost::APPROVED, GbpQueuedPost::PUBLISHED], true))->count();
            $source = $sources[(int) $location->brand_id] ?? ['sites' => 0, 'pages' => 0];
            $state = $states[$location->id] ?? null;
            $info[$location->id] = [
                'strip' => $strip,
                'planned' => $planned,
                'drafts' => collect($strip)->filter(fn (string $s): bool => $s === GbpQueuedPost::DRAFT)->count(),
                'approved' => $future->filter(fn (string $s): bool => $s === GbpQueuedPost::APPROVED)->count(),
                'week' => collect($strip)->filter(fn (string $s, string $d): bool => $s === GbpQueuedPost::APPROVED && $d <= $weekEnd)->count(),
                'failed' => $failed,
                'reason' => match (true) {
                    $planned >= self::LOW_DAYS => null,
                    $source['sites'] === 0 => 'Markaya web sitesi bağlı değil',
                    $source['pages'] === 0 => 'Sitede gönderi yazılabilecek Türkçe hizmet / blog sayfası yok',
                    $state === null || ($state['status'] ?? '') === 'running' => 'Henüz hazırlanmadı',
                    default => 'Sayfalar yetmiyor: '.$source['pages'].' uygun sayfa var',
                },
                'state' => $state,
            ];
        }
        $matches = fn (DigitalAsset $l): bool => match ($this->filter) {
            'draft' => $info[$l->id]['drafts'] > 0,
            'week' => $info[$l->id]['week'] > 0,
            'failed' => $info[$l->id]['failed'] > 0,
            'low' => $info[$l->id]['planned'] < self::LOW_DAYS,
            default => true,
        };
        $totals = [
            'draft' => array_sum(array_column($info, 'drafts')),
            'week' => array_sum(array_column($info, 'week')),
            'failed' => array_sum(array_column($info, 'failed')),
            'low' => count(array_filter($info, fn (array $i): bool => $i['planned'] < self::LOW_DAYS)),
        ];
        $brands = $locations->filter($matches)->groupBy('brand_id')
            ->map(fn ($group): array => ['brand' => $group->first()->brand, 'locations' => $group->values()])
            ->sortBy(fn (array $b): string => mb_strtolower((string) $b['brand']?->name))->values();

        $rows = collect();
        if ($this->location !== null && $this->read === null) {
            $rows = GbpQueuedPost::query()->where('digital_asset_id', $this->location)
                ->when($this->day !== null, fn ($q) => $q->where('publish_on', $this->day), fn ($q) => $q->whereBetween('publish_on', [$today->subDays(7)->toDateString(), end($days)]))
                ->with(['page:id,title,url', 'writeAction:id,status,error'])->orderBy('publish_on')->orderBy('id')->get();
        }
        $reading = collect();
        $readTotal = 0;
        if ($this->read !== null) {
            $base = GbpQueuedPost::query()->where('brand_id', $this->read)->whereIn('digital_asset_id', $locations->pluck('id'))
                ->where('status', GbpQueuedPost::DRAFT)->where('publish_on', '>=', $days[0]);
            $readTotal = (clone $base)->count();
            $reading = $base->with(['page:id,title,url', 'digitalAsset:id,name', 'writeAction:id,status,error'])->orderBy('publish_on')->orderBy('digital_asset_id')->limit($this->readLimit)->get();
        }

        return view('livewire.operator.gbp.post-plan-page', [
            'brands' => $brands,
            'info' => $info,
            'totals' => $totals,
            'filters' => self::FILTERS,
            'days' => $days,
            'rows' => $rows,
            'reading' => $reading,
            'readTotal' => $readTotal,
            'readBrand' => $this->read !== null ? $locations->firstWhere('brand_id', $this->read)?->brand : null,
            'angles' => GbpPostQueue::ANGLES,
            'canWrite' => ExternalWriteService::allowed(auth()->user(), ExternalWriteAction::CHANNEL_GBP),
            'horizon' => GbpPostQueue::HORIZON_DAYS,
            'lowDays' => self::LOW_DAYS,
        ]);
    }

    private function post(int $id): GbpQueuedPost
    {
        return GbpQueuedPost::query()->with('digitalAsset.brand', 'writeAction')->findOrFail($id);
    }
}
