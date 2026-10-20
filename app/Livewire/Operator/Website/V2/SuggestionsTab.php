<?php

namespace App\Livewire\Operator\Website\V2;

use App\Models\Cluster;
use App\Models\DigitalAsset;
use App\Models\ExternalWriteAction;
use App\Models\Page;
use App\Models\Suggestion;
use App\Services\ExternalWrites\ExternalWriteService;
use App\Services\Outcomes\OutcomeTracker;
use App\Services\Repair\RepairDesk;
use App\Services\Site\ChangeApplier;
use App\Services\Site\ClusterOverlaps;
use App\Services\Site\ImageAlts;
use App\Services\Site\OverlapAutopilot;
use App\Services\Site\ScopedStandards;
use App\Services\Site\SiteDiff;
use App\Services\Site\SiteOperations;
use App\Services\Site\SiteSuggestions;
use App\Services\Site\SiteSuggestionTypes;
use App\Services\Work\PageTraffic;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * SEO Yapılacaklar › Öneriler: URL analysis suggestions of this site with filters (URL, tür, durum), grouped by issue type
 * in plain words (yakup, 2026-10-19). Groups and rows follow their expected gain (RepairDesk::gain on the page's Search
 * Console traffic); rows of pages Google never showed sit in their own "Düşük etkili" group at the end. Every row says
 * what is wrong and what the system will do, and its one button names the result:
 * - aynı konuyu anlatan sayfalar: Birleştir (301, page to draft), Metni ayır (AI rewrite → Siteye gönder → Canlıya al),
 *   Bunu ana sayfa yap (inside MoxDOP only) — OverlapAutopilot, the operator's own click, undoable on the site;
 * - work the system writes: Düzeltmeyi hazırla (prepared, then waits on the Onarım masası) or Siteye gönder;
 * - work done by hand: Yaptım. "Gerek yok" closes a row with a reason, "Neden?" opens the detail.
 * A group can send all its ready rows at once. "Bu karardan standart öner" (edit + approve → scoped, versioned standard).
 */
final class SuggestionsTab extends Component
{
    use WithPagination;

    #[Locked]
    public int $assetId = 0;

    #[Url(as: 'url')]
    public string $pageFilter = '';

    #[Url(as: 'tur')]
    public string $type = '';

    #[Url(as: 'durum')]
    public string $status = 'open';

    /** @var array<int, string> */
    public array $reasons = [];

    /** Open suggestion; İçerik fikirleri "Önizle ve güncelle" links here with it. */
    #[Url(as: 'oneri')]
    public ?int $openId = null;

    /** @var array{title?: string, rule?: string, condition?: string, exceptions?: string, scope?: string} */
    public array $standard = [];

    public string $message = '';

    public const array STATUS_LABELS = ['open' => 'Bekliyor', 'approved' => 'Sürüyor', 'applied' => 'Sitede', 'dismissed' => 'Gerek yok dendi', 'recheck' => 'Sayfa değişti, yeniden bakılacak'];

    /** A site write's state in plain words. */
    public const array WRITE_STATUS_LABELS = ['pending_approval' => 'onay bekliyor', 'approved' => 'sırada', 'queued' => 'sırada', 'running' => 'siteye yazılıyor',
        'succeeded' => 'siteye yazıldı', 'failed' => 'yazılamadı', 'cancelled' => 'iptal edildi', 'undoing' => 'geri alınıyor', 'undone' => 'geri alındı', 'undo_failed' => 'geri alınamadı'];

    /** "Gerek yok" one-tap reasons. */
    public const array DISMISS_REASONS = ['Farklı konu', 'Yanlış tespit', 'Marka istemiyor', 'Zaten yapıldı'];

    /** Rows of pages Google never showed (site with Search Console data): one group at the end. */
    public const string LOW = '_low';

    public const int PER_PAGE = 30;

    public function mount(int $assetId): void
    {
        $this->assetId = $assetId;
    }

    public function updated(string $property): void
    {
        if (in_array($property, ['pageFilter', 'type', 'status'], true)) {
            $this->resetPage();
        }
    }

    public function open(int $id): void
    {
        $this->openId = $this->openId === $id ? null : $id;
        $draft = (array) data_get($this->suggestion($id)->action, 'standard_draft', []);
        $this->standard = $draft;
    }

    /** "Düzeltmeyi hazırla" (the desk sends it) or "Yaptım" (work done by hand). */
    public function approve(int $id, SiteSuggestions $suggestions): void
    {
        $this->message = $suggestions->approve($this->suggestion($id), auth()->user());
    }

    /** "Gerek yok": closes the row with its reason (an overlap: both pages stay, not proposed again until it changes). */
    public function dismiss(int $id, SiteSuggestions $suggestions, ClusterOverlaps $overlaps): void
    {
        $suggestion = $this->suggestion($id);
        if ($suggestion->action_type === ClusterOverlaps::TYPE) {
            $overlaps->keep($suggestion, auth()->user(), (string) ($this->reasons[$id] ?? ''));
        } else {
            $suggestions->dismiss($suggestion, auth()->user(), (string) ($this->reasons[$id] ?? ''));
        }
        unset($this->reasons[$id]);
        $this->message = 'Kapatıldı; bir daha önerilmez.';
    }

    /** The overlap's own button: redirect (Birleştir), rewrite (Metni ayır), publish, go_live, make_main. */
    public function overlap(int $id, string $decision, OverlapAutopilot $autopilot): void
    {
        if (! in_array($decision, ['redirect', 'rewrite', 'publish', 'go_live', 'make_main'], true)) {
            return;
        }
        $suggestion = $this->suggestion($id);
        $this->message = (string) ($autopilot->act([(int) $suggestion->id], $decision, auth()->user())[(int) $suggestion->id] ?? '');
        if ($decision === 'redirect' && ClusterOverlaps::mergePending($suggestion->fresh() ?? $suggestion)) {
            $this->message = 'Yönlendirme siteye gönderildi; site onaylayınca «Sitede» olur. Geri alınabilir.';
        }
    }

    /**
     * The group's one button: every ready row of the type on this site (the URL filter applies). Overlaps: each
     * "Birleştir" Claude did not find to be a different question; fixes: ready title / description / link versions
     * (page text goes one by one); image alts: every prepared page.
     */
    public function sendGroup(string $type, ClusterOverlaps $overlaps, ChangeApplier $changes, ImageAlts $alts): void
    {
        $rows = $this->groupReady($type);
        if ($rows->isEmpty()) {
            $this->message = 'Gönderilecek hazır iş yok.';

            return;
        }
        $user = auth()->user();
        $sent = 0;
        $failed = [];
        if ($type === ClusterOverlaps::TYPE) {
            try {
                $sent = $overlaps->redirectMany($rows, $user);
            } catch (ValidationException $exception) {
                $failed[] = (string) collect($exception->errors())->flatten()->first();
            }
        } else {
            foreach ($rows as $row) {
                try {
                    $type === ImageAlts::TYPE ? $alts->approve($row, $user) : $changes->approve($row, $user);
                    $sent++;
                } catch (ValidationException $exception) {
                    $failed[] = (string) collect($exception->errors())->flatten()->first();
                }
            }
        }
        $this->message = $sent.' iş siteye gönderildi (geri alınabilir).'.($failed !== [] ? ' Gönderilemeyen: '.count($failed).' · '.$failed[0] : '');
    }

    public function aiDo(int $id): void
    {
        $suggestion = $this->suggestion($id);
        if (! SiteSuggestionTypes::applicable((string) $suggestion->action_type)) {
            $this->message = 'Bu öneri türü elle uygulanır.';

            return;
        }
        SiteOperations::dispatch($this->assetId, SiteOperations::APPLY_CHANGE, ['suggestion_id' => $id]);
        $this->openId = $id;
        $this->message = 'Yeni sürüm hazırlanıyor.';
    }

    public function applyChange(int $id, ChangeApplier $changes): void
    {
        $count = count($changes->approve($this->suggestion($id), auth()->user()));
        $this->message = $count.' WordPress işlemi kuyruğa alındı (geri alınabilir).';
    }

    public function publishDraft(int $id, ChangeApplier $changes): void
    {
        $changes->publishDraft($this->suggestion($id), auth()->user());
        $this->message = 'Taslak canlıya alınıyor (geri alınabilir).';
    }

    public function undo(int $writeId, ExternalWriteService $writes): void
    {
        $action = ExternalWriteAction::query()->whereIn('suggestion_id', Suggestion::query()->where('brand_id', $this->brandId())->select('id'))->findOrFail($writeId);
        $writes->requestUndo(auth()->user(), $action);
        $this->message = 'Geri alma kuyruğa alındı.';
    }

    /** "Görsel alt metni öner": AI proposals for WordPress images without alt text (one suggestion per page). */
    public function proposeAlts(): void
    {
        SiteOperations::dispatch($this->assetId, SiteOperations::IMAGE_ALTS);
        $this->message = 'Görsel alt metinleri hazırlanıyor.';
    }

    public function applyAlts(int $id, ImageAlts $alts): void
    {
        $alts->approve($this->suggestion($id), auth()->user());
        $this->message = 'Alt metinler WordPress’e gönderiliyor (geri alınabilir).';
    }

    public function proposeStandard(int $id): void
    {
        $this->suggestion($id);
        SiteOperations::dispatch($this->assetId, SiteOperations::STANDARD, ['suggestion_id' => $id]);
        $this->openId = $id;
        $this->message = 'Standart önerisi hazırlanıyor.';
    }

    public function saveStandard(int $id, ScopedStandards $standards): void
    {
        $standards->save($this->suggestion($id), $this->standard, auth()->user());
        $this->standard = [];
        $this->message = 'Standart kütüphaneye eklendi (sürüm 1).';
    }

    public function render(): View
    {
        $site = DigitalAsset::query()->findOrFail($this->assetId);
        $ranked = $this->ranked();
        $groups = $this->groups($ranked);
        $shown = $this->type === '' ? $ranked : $ranked->where('group', $this->type)->values();
        // Pages walk the groups in order (most valuable group first), so a group's rows stay together under its heading.
        $order = array_flip(array_keys($groups));
        $shown = $shown->sortBy(fn (array $r): array => [$order[$r['group']] ?? 999, -$r['gain'], $r['priority'], -$r['id']])->values();
        $page = max(1, (int) $this->getPage());
        $slice = $shown->slice(($page - 1) * self::PER_PAGE, self::PER_PAGE)->values();
        $models = Suggestion::query()->with('page:id,url,path,title,h1,website_asset_id')->whereIn('id', $slice->pluck('id')->all() ?: [0])->get()->keyBy('id');
        $items = $slice->map(fn (array $r): ?Suggestion => $models->get($r['id'])?->setAttribute('group', $r['group']))->filter()->values();
        $suggestions = new LengthAwarePaginator($items, $shown->count(), self::PER_PAGE, $page, ['path' => request()->url(), 'pageName' => 'page']);
        $opened = $this->openId !== null ? $items->firstWhere('id', $this->openId) : null;
        if ($opened !== null && $this->standard === [] && is_array(data_get($opened->action, 'standard_draft'))) {
            $this->standard = (array) data_get($opened->action, 'standard_draft');
        }
        $proposal = $opened !== null ? (array) data_get($opened->action, 'proposal', []) : [];

        return view('livewire.operator.website.v2.suggestions-tab', [
            'suggestions' => $suggestions,
            'groups' => $groups,
            'cards' => $this->cards($items),
            'opened' => $opened,
            'proposal' => $proposal,
            'diff' => isset($proposal['new']['html']) ? SiteDiff::html((string) ($proposal['current']['html'] ?? ''), (string) $proposal['new']['html']) : null,
            'writes' => $opened !== null ? ExternalWriteAction::query()->where('suggestion_id', $opened->id)->orderBy('id')->get() : collect(),
            'pageOptions' => Page::query()->where('website_asset_id', $site->id)->whereIn('id', Suggestion::query()->where('brand_id', (int) $site->brand_id)->whereNotNull('page_id')->select('page_id'))->orderBy('path')->pluck('path', 'id')->all(),
            'applyStatus' => $opened !== null ? SiteOperations::line(SiteOperations::status($site->id, SiteOperations::APPLY_CHANGE, ['suggestion_id' => $opened->id])) : null,
            'altStatus' => SiteOperations::line(SiteOperations::status($site->id, SiteOperations::IMAGE_ALTS)),
            'standardStatus' => $opened !== null ? SiteOperations::line(SiteOperations::status($site->id, SiteOperations::STANDARD, ['suggestion_id' => $opened->id])) : null,
        ]);
    }

    /**
     * Rows of the filtered list (URL, durum) with their group and expected gain: RepairDesk::gain on the page's Search
     * Console traffic (90 days); an overlap counts its main page's traffic too, since the need is what is at stake.
     * A page-bound row of a site with Search Console data whose pages Google never showed goes to the LOW group.
     *
     * @return Collection<int, array{id: int, group: string, gain: float, priority: int}>
     */
    private function ranked(): Collection
    {
        $site = DigitalAsset::query()->findOrFail($this->assetId);
        $rows = Suggestion::query()->where('brand_id', (int) $site->brand_id)->where('channel', 'search')
            ->where('action_type', '!=', SiteSuggestionTypes::CONTENT)
            ->where(fn (Builder $q) => $this->ofSite($q))
            ->when($this->pageFilter !== '' && ctype_digit($this->pageFilter), fn (Builder $q) => $q->where('page_id', (int) $this->pageFilter))
            ->when($this->status !== '', fn (Builder $q) => $q->where('status', $this->status))
            ->get(['id', 'action_type', 'priority', 'page_id', 'action']);
        $mainIds = $rows->where('action_type', ClusterOverlaps::TYPE)->map(fn (Suggestion $s): int => (int) data_get($s->action, 'main_page_id'));
        $traffic = app(PageTraffic::class)->forPages($rows->pluck('page_id')->merge($mainIds));

        return $rows->map(function (Suggestion $s) use ($traffic): array {
            $ids = array_filter([(int) $s->page_id, $s->action_type === ClusterOverlaps::TYPE ? (int) data_get($s->action, 'main_page_id') : 0]);
            $known = array_filter(array_map(fn (int $id): ?array => array_key_exists($id, $traffic) ? $traffic[$id] : null, $ids), fn (?array $t): bool => $t !== null);
            $row = ['priority' => (int) ($s->priority ?? 3)] + ($known === [] ? [] : ['impressions' => (int) array_sum(array_column($known, 'impressions')), 'clicks' => (int) array_sum(array_column($known, 'clicks'))]);
            $gain = RepairDesk::gain($row);
            $low = $known !== [] && $gain <= 0.0;

            return ['id' => (int) $s->id, 'group' => $low ? self::LOW : (string) $s->action_type, 'gain' => $gain, 'priority' => $row['priority']];
        })->values();
    }

    /**
     * Issue-type groups of the filtered list in plain words, the most valuable first (summed gain), "Düşük etkili" last.
     *
     * @param  Collection<int, array{id: int, group: string, gain: float, priority: int}>  $ranked
     * @return array<string, array{label: string, count: int, ready: int}>
     */
    private function groups(Collection $ranked): array
    {
        $groups = $ranked->groupBy('group')->map(fn (Collection $rows, string $group): array => [
            'label' => $group === self::LOW ? 'Google\'da görünmeyen sayfalar (düşük etkili)' : SiteSuggestionTypes::label($group),
            'count' => $rows->count(), 'gain' => (float) $rows->sum('gain'), 'ready' => $group === self::LOW ? 0 : $this->groupReady($group)->count(),
        ])->sortBy(fn (array $g, string $group): array => [$group === self::LOW ? 1 : 0, -$g['gain'], -$g['count']]);

        return $groups->map(fn (array $g): array => ['label' => $g['label'], 'count' => $g['count'], 'ready' => $g['ready']])->all();
    }

    /**
     * Rows of one type the group button sends: open, prepared, nothing written yet.
     *
     * @return Collection<int, Suggestion>
     */
    private function groupReady(string $type): Collection
    {
        if ($type !== ClusterOverlaps::TYPE && $type !== ImageAlts::TYPE && ! SiteSuggestions::sendable($type)) {
            return collect();
        }
        $site = DigitalAsset::query()->findOrFail($this->assetId);

        return Suggestion::query()->where('brand_id', (int) $site->brand_id)->where('action_type', $type)
            ->whereIn('status', [Suggestion::OPEN, Suggestion::RECHECK])->whereNull('applied_at')
            ->where(fn (Builder $q) => $this->ofSite($q))
            ->when($this->pageFilter !== '' && ctype_digit($this->pageFilter), fn (Builder $q) => $q->where('page_id', (int) $this->pageFilter))
            ->get()->filter(fn (Suggestion $s): bool => match (true) {
                $type === ClusterOverlaps::TYPE => ClusterOverlaps::mergeable($s) && data_get($s->action, 'check.verdict') !== 'different',
                $type === ImageAlts::TYPE => (array) data_get($s->action, 'images', []) !== [],
                default => self::ready($s) && ! filled(data_get($s->action, 'proposal.new.html')),
            })->values();
    }

    /** A prepared version that passed its checks and was not sent. */
    public static function ready(Suggestion $suggestion): bool
    {
        $action = (array) $suggestion->action;

        return (array) ($action['proposal'] ?? []) !== [] && ! isset($action['proposal_blocked']) && ! isset($action['writes']) && $suggestion->applied_at === null;
    }

    /**
     * What each row says and its one button, in plain words.
     *
     * @param  Collection<int, Suggestion>  $items
     * @return array<int, array{heading: string, sentence: string, where: string, button: ?array{label: string, call: string, confirm: ?string}, note: ?string, outcome: ?string}>
     */
    private function cards(Collection $items): array
    {
        $overlaps = $items->where('action_type', ClusterOverlaps::TYPE);
        $mains = Page::query()->whereIn('id', $overlaps->map(fn (Suggestion $s): int => (int) data_get($s->action, 'main_page_id'))->all() ?: [0])->get(['id', 'url', 'path', 'title', 'h1'])->keyBy('id');
        $clusters = Cluster::query()->whereIn('id', $overlaps->pluck('cluster_id')->filter()->all() ?: [0])->pluck('name', 'id');
        $cards = [];
        foreach ($items as $s) {
            $action = (array) $s->action;
            $type = (string) $s->action_type;
            $actionable = in_array($s->status, [Suggestion::OPEN, Suggestion::RECHECK], true);
            $card = ['heading' => (string) $s->title, 'sentence' => (string) $s->reason, 'where' => (string) ($s->page?->path ?? ''), 'button' => null, 'note' => null,
                'outcome' => $s->status === Suggestion::APPLIED ? OutcomeTracker::sentence($s) : null];
            if ($type === ClusterOverlaps::TYPE) {
                $main = $mains->get((int) ($action['main_page_id'] ?? 0));
                $pageName = ClusterOverlaps::pageName($s->page);
                $mainName = ClusterOverlaps::pageName($main);
                $card['heading'] = ClusterOverlaps::heading($action, (string) ($clusters[(int) $s->cluster_id] ?? ''));
                $card['sentence'] = ClusterOverlaps::sentence($action, $pageName, $mainName);
                $card['where'] = (string) ($s->page?->path ?? '').' → '.($main?->path ?? '');
                if (filled(data_get($action, 'check.reason'))) {
                    $card['note'] = 'Claude kontrol etti: '.data_get($action, 'check.reason');
                }
                if (filled($action['merge_error'] ?? null)) {
                    $card['note'] = 'Önceki gönderim olmadı: '.$action['merge_error'];
                }
                $card['button'] = match (true) {
                    ClusterOverlaps::mergePending($s) => ['label' => 'Siteye yazılıyor…', 'call' => '', 'confirm' => null],
                    ! $actionable => null,
                    ($action['recommendation'] ?? null) === ClusterOverlaps::REDIRECT => ['label' => 'Birleştir', 'call' => "overlap({$s->id}, 'redirect')",
                        'confirm' => '«'.$pageName.'» ziyaretçileri «'.$mainName.'» sayfasına yönlendirilsin ve sayfa taslağa alınsın mı? Silinmez, geri alınabilir.'],
                    ($action['recommendation'] ?? null) === ClusterOverlaps::REVIEW => ['label' => 'Bunu ana sayfa yap', 'call' => "overlap({$s->id}, 'make_main')",
                        'confirm' => 'Bu konunun ana sayfası «'.$pageName.'» olsun mu? Yalnız MoxDOP içinde değişir, siteye bir şey yazılmaz.'],
                    data_get($action, 'rewrite.state') === 'preparing' => ['label' => 'Yeni metin yazılıyor…', 'call' => '', 'confirm' => null],
                    data_get($action, 'rewrite.state') === 'ready' => ['label' => 'Yeni metni siteye gönder', 'call' => "overlap({$s->id}, 'publish')",
                        'confirm' => 'Yeni metin siteye gönderilsin mi? Başlık ve açıklama hemen, sayfa metni önce taslak olarak gider. Geri alınabilir.'],
                    data_get($action, 'rewrite.state') === 'publishing' => ['label' => 'Canlıya al', 'call' => "overlap({$s->id}, 'go_live')", 'confirm' => 'Taslaktaki yeni metin canlıya alınsın mı? Geri alınabilir.'],
                    default => ['label' => 'Metni ayır', 'call' => "overlap({$s->id}, 'rewrite')", 'confirm' => null],
                };
                if (in_array(data_get($action, 'rewrite.state'), ['blocked', 'failed', 'needs_claude'], true)) {
                    $card['note'] = 'Yeni metin: '.data_get($action, 'rewrite.message');
                }
            } elseif ($type === ImageAlts::TYPE) {
                $card['button'] = $actionable ? ['label' => 'Alt metinleri yaz', 'call' => "applyAlts({$s->id})", 'confirm' => 'Resim açıklamaları siteye yazılsın mı? Geri alınabilir.'] : null;
            } elseif (SiteSuggestions::sendable($type)) {
                $card['button'] = match (true) {
                    ! in_array($s->status, [Suggestion::OPEN, Suggestion::RECHECK, Suggestion::APPROVED], true) || $s->applied_at !== null => null,
                    self::ready($s) => ['label' => 'Siteye gönder', 'call' => "applyChange({$s->id})", 'confirm' => 'Hazırlanan düzeltme siteye gönderilsin mi? Geri alınabilir.'],
                    isset($action['proposal_blocked']) => ['label' => 'Yeniden hazırla', 'call' => "aiDo({$s->id})", 'confirm' => null],
                    (SiteOperations::status($this->assetId, SiteOperations::APPLY_CHANGE, ['suggestion_id' => (int) $s->id])['status'] ?? null) === 'running' => ['label' => 'Hazırlanıyor…', 'call' => '', 'confirm' => null],
                    default => ['label' => 'Düzeltmeyi hazırla', 'call' => "approve({$s->id})", 'confirm' => null],
                };
            } elseif ($actionable) {
                $card['button'] = ['label' => 'Yaptım', 'call' => "approve({$s->id})", 'confirm' => 'Bunu sitede elle yaptın mı? Sistem sonraki taramada kontrol eder.'];
            }
            $cards[(int) $s->id] = $card;
        }

        return $cards;
    }

    private function brandId(): int
    {
        return (int) DigitalAsset::query()->whereKey($this->assetId)->value('brand_id');
    }

    private function suggestion(int $id): Suggestion
    {
        return Suggestion::query()->where('brand_id', $this->brandId())->where(fn (Builder $q) => $this->ofSite($q))->findOrFail($id);
    }

    /**
     * Suggestions of this site's pages, and those whose page was deleted (kept for the re-check, site in the action).
     *
     * @param  Builder<Suggestion>  $query
     * @return Builder<Suggestion>
     */
    private function ofSite(Builder $query): Builder
    {
        return $query->whereIn('page_id', Page::query()->where('website_asset_id', $this->assetId)->select('id'))
            ->orWhere(fn (Builder $gone) => $gone->whereNull('page_id')->where('status', Suggestion::RECHECK)->where('action->site_id', $this->assetId));
    }
}
