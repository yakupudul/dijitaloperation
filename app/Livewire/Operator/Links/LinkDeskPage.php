<?php

namespace App\Livewire\Operator\Links;

use App\Jobs\Site\WriteLinkPack;
use App\Models\BacklinkSource;
use App\Models\Brand;
use App\Models\LinkSource;
use App\Models\LinkSurvey;
use App\Models\LinkTarget;
use App\Models\ServiceCategory;
use App\Services\Site\Backlinks\LinkDesk;
use App\Services\Site\Backlinks\LinkMagnets;
use App\Services\Site\Backlinks\LinkMailer;
use App\Services\Site\Backlinks\LinkMentions;
use App\Services\Site\Backlinks\LinkSurveys;
use App\Services\Site\SiteDomains;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Bağlantılar (Backlink hizmeti, yakup 2026-11-06): the Bağlantı masası of every brand on one screen.
 * Masa = rows with their material ready (copy, open, "Başvurdum", "Link verildi"), the "sen yap" lane (calls, visits,
 * memberships), the ones waiting for an answer and the links of this month. Hedefler = each brand's package, this
 * month's links and the pages that need link strength with their link text plan. Havuz = the shared sources by sector
 * and city, what each brought, and the quality verdict. Nothing is sent from here by itself.
 */
#[Layout('operator.layouts.app')]
#[Title('Bağlantılar')]
final class LinkDeskPage extends Component
{
    public const array TABS = ['masa' => 'Masa', 'hedefler' => 'Hedefler', 'havuz' => 'Kaynak havuzu'];

    #[Url(as: 'sekme', except: 'masa')]
    public string $tab = 'masa';

    #[Url(as: 'marka', except: 0)]
    public int $brand = 0;

    #[Url(as: 'q', except: '')]
    public string $q = '';

    public ?int $openRow = null;

    /** @var array<int, string> link page per row, typed by the operator */
    public array $linkUrl = [];

    /** @var array{name: string, url: string, kind: string, city: string, apply_via: string, contact_email: string, sector_id: string} */
    public array $source = ['name' => '', 'url' => '', 'kind' => 'dizin', 'city' => '', 'apply_via' => 'form', 'contact_email' => '', 'sector_id' => ''];

    public string $message = '';

    /** @var array<int, string> the client form link shown under a brand */
    public array $surveyUrl = [];

    /** @var array<int, string> e-mail address per row, typed by the operator when the source has none */
    public array $recipient = [];

    /** @var array<int, string> Google Alerts RSS feed per brand */
    public array $alertFeed = [];

    public function updatedTab(): void
    {
        if (! isset(self::TABS[$this->tab])) {
            $this->tab = 'masa';
        }
    }

    public function toggle(int $id): void
    {
        $this->openRow = $this->openRow === $id ? null : $id;
    }

    public function sent(int $id, LinkDesk $desk): void
    {
        $row = $this->row($id);
        $desk->sent($row);
        $this->message = $row->name.': başvuru işaretlendi. '.BacklinkSource::FOLLOW_UP_DAYS.' gün içinde cevap gelmezse takip hatırlatılır.';
    }

    public function given(int $id, LinkDesk $desk): void
    {
        $this->resetErrorBag('linkUrl.'.$id);
        $row = $this->row($id);
        $url = trim((string) ($this->linkUrl[$id] ?? ''));
        if (! preg_match('#^https?://#i', $url) || SiteDomains::host($url) === null) {
            $this->addError('linkUrl.'.$id, 'Linkin bulunduğu sayfanın tam adresini yaz (https://…).');

            return;
        }
        $desk->given($row, $url);
        unset($this->linkUrl[$id]);
        $this->message = $row->name.': link sayfası kaydedildi, sayfa şimdi kontrol ediliyor.';
    }

    public function drop(int $id, LinkDesk $desk): void
    {
        $row = $this->row($id);
        $desk->drop($row, 'Operatör vazgeçti · '.now('Europe/Istanbul')->format('d.m.Y'));
        $this->openRow = null;
        $this->message = $row->name.' masadan kalktı.';
    }

    public function reopen(int $id, LinkDesk $desk): void
    {
        $desk->reopen($this->row($id));
    }

    public function rewrite(int $id): void
    {
        $row = $this->row($id);
        if ($row->status !== BacklinkSource::NONE) {
            return;
        }
        $row->forceFill(['material_status' => 'queued', 'material_note' => null])->save();
        WriteLinkPack::dispatch((int) $row->id);
        $this->message = $row->name.': malzeme yeniden yazılıyor.';
    }

    /** The row's first e-mail leaves from the agency's mail settings (only with this click). */
    public function sendMail(int $id, LinkMailer $mailer): void
    {
        $row = $this->row($id);
        if (! LinkMailer::mailReady($row)) {
            $this->message = $row->name.': gönderilecek hazır e-posta ya da alıcı yok.';

            return;
        }
        $error = $mailer->send($row, 'ilk', auth()->id());
        $this->message = $row->name.': '.($error ?? 'e-posta gönderildi. '.BacklinkSource::FOLLOW_UP_DAYS.' gün içinde cevap gelmezse kısa bir hatırlatma hazırlanır.');
    }

    /** Every ready e-mail on the screen (the brand filter applies), one click. */
    public function sendAll(LinkMailer $mailer): void
    {
        if (! $mailer->canSend()) {
            $this->message = 'E-posta ayarı yok (Ayarlar › E-posta). "Postada aç" ile kendi postanızdan gönderebilirsiniz.';

            return;
        }
        [$sent, $failed] = [0, 0];
        foreach ($this->mailRows() as $row) {
            if (LinkMailer::mailReady($row)) {
                $mailer->send($row, 'ilk', auth()->id()) === null ? $sent++ : $failed++;
            }
        }
        $this->message = $sent.' e-posta gönderildi'.($failed > 0 ? ', '.$failed.' gönderilemedi (satırda nedeni yazıyor).' : '.');
    }

    public function sendFollowUp(int $id, LinkMailer $mailer): void
    {
        $row = $this->row($id);
        if (! LinkMailer::followUpDue($row)) {
            return;
        }
        $error = $mailer->send($row, 'takip', auth()->id());
        $this->message = $row->name.': '.($error ?? 'hatırlatma gönderildi. '.(LinkMailer::GIVE_UP_DAYS - BacklinkSource::FOLLOW_UP_DAYS).' gün daha cevap gelmezse satır masadan kalkar.');
    }

    public function setRecipient(int $id): void
    {
        $this->resetErrorBag('recipient.'.$id);
        $row = $this->row($id);
        $to = trim((string) ($this->recipient[$id] ?? ''));
        if (filter_var($to, FILTER_VALIDATE_EMAIL) === false) {
            $this->addError('recipient.'.$id, 'Geçerli bir e-posta adresi yaz.');

            return;
        }
        $row->forceFill(['material' => [...(array) $row->material, 'to' => $to]])->save();
        if ($row->pool !== null && blank($row->pool->contact_email)) {
            $row->pool->forceFill(['contact_email' => $to])->save();
        }
        unset($this->recipient[$id]);
    }

    /** The brand's Google Alerts RSS feed (anılma motoru); empty clears it. */
    public function setAlertFeed(int $brandId, LinkMentions $mentions): void
    {
        $this->resetErrorBag('alertFeed.'.$brandId);
        $feed = trim((string) ($this->alertFeed[$brandId] ?? ''));
        if ($feed !== '' && ! preg_match('#^https://(www\.)?google\.[a-z.]+/alerts/feeds/#i', $feed)) {
            $this->addError('alertFeed.'.$brandId, 'Google Alerts\'ta uyarının "RSS akışı" adresini yapıştır (https://www.google.com/alerts/feeds/…).');

            return;
        }
        $brand = Brand::query()->findOrFail($brandId);
        $brand->forceFill(['link_alert_feed' => $feed !== '' ? $feed : null])->save();
        $out = $feed !== '' ? $mentions->read($brand->fresh()) : ['read' => 0, 'added' => 0];
        $this->message = $feed === '' ? $brand->name.': uyarı akışı kaldırıldı.'
            : $brand->name.': uyarı akışı kaydedildi; '.$out['read'].' sayfa okundu, '.$out['added'].' anılma satırı eklendi. Akış her sabah okunur.';
    }

    /** @return Collection<int, BacklinkSource> */
    private function mailRows(): Collection
    {
        return BacklinkSource::query()->with(['pool', 'brand'])->where('status', BacklinkSource::NONE)->where('material_status', 'ready')
            ->whereHas('brand', fn (Builder $q) => $q->operational())->when($this->brand > 0, fn (Builder $q) => $q->where('brand_id', $this->brand))
            ->orderBy('id')->limit(100)->get();
    }

    public function setPackage(int $brandId, string $package): void
    {
        if (! isset(LinkDesk::PACKAGES[$package])) {
            return;
        }
        Brand::query()->findOrFail($brandId)->forceFill(['link_package' => $package])->save();
        $this->message = 'Paket kaydedildi.';
    }

    public function fillBrand(int $brandId, LinkDesk $desk): void
    {
        $brand = Brand::query()->with('customer')->findOrFail($brandId);
        $out = $desk->fill($brand);
        $this->message = $brand->name.': '.($out['targets']['picked'] + $out['targets']['kept']).' hedef sayfa, '.$out['added'].' yeni satır'
            .($out['added'] > 0 ? ' (malzemeleri Claude yazıyor).' : '.');
    }

    /** The brand's link magnet subject goes to İçerik as a title waiting for approval. */
    public function proposeMagnet(int $brandId, LinkMagnets $magnets): void
    {
        $brand = Brand::query()->findOrFail($brandId);
        $why = $magnets->propose($brand);
        $this->message = $brand->name.': '.($why ?? 'mıknatıs konusu İçerik\'e eklendi; onaylayınca yazılır, yayınlanınca masa bu sayfaya link ister.');
    }

    /** The client's "Bağlantı kaynakları" form link of a brand (made once, then the same link). */
    public function surveyLink(int $brandId, LinkSurveys $surveys): void
    {
        $brand = Brand::query()->findOrFail($brandId);
        $this->surveyUrl = [$brandId => LinkSurveys::url($surveys->for($brand))];
    }

    public function addSource(): void
    {
        $this->resetErrorBag();
        $data = $this->validate([
            'source.name' => ['required', 'string', 'max:200'], 'source.url' => ['required', 'url:https', 'max:2000'],
            'source.kind' => ['required', 'in:'.implode(',', array_keys(LinkSource::KINDS))], 'source.city' => ['nullable', 'string', 'max:80'],
            'source.apply_via' => ['required', 'in:'.implode(',', array_keys(LinkSource::APPLY_VIA))], 'source.contact_email' => ['nullable', 'email', 'max:255'],
            'source.sector_id' => ['nullable', 'integer'],
        ], [], ['source.name' => 'ad', 'source.url' => 'adres'])['source'];
        $domain = SiteDomains::host($data['url']);
        if ($domain === null) {
            $this->addError('source.url', 'Adres okunamadı.');

            return;
        }
        $sector = filled($data['sector_id']) ? ServiceCategory::query()->find((int) $data['sector_id']) : null;
        $pool = LinkDesk::absorb(['name' => $data['name'], 'url' => $data['url'], 'domain' => $domain, 'kind' => $data['kind'], 'city' => $data['city'] ?: null,
            'apply_via' => $data['apply_via'], 'contact_email' => $data['contact_email'] ?: null], null, 'manual');
        $pool->forceFill(['sector_id' => $sector?->id ?? $pool->sector_id, 'quality' => 'uygun', 'quality_note' => 'Elle eklendi'])->save();
        $this->source = ['name' => '', 'url' => '', 'kind' => 'dizin', 'city' => '', 'apply_via' => 'form', 'contact_email' => '', 'sector_id' => ''];
        $this->message = $pool->name.' havuza eklendi; sektörün ve şehrin markalarına bir sonraki dolumda önerilir.';
    }

    public function setQuality(int $id, string $quality): void
    {
        if (! isset(LinkSource::QUALITY[$quality])) {
            return;
        }
        $source = LinkSource::query()->findOrFail($id);
        $source->forceFill(['quality' => $quality, 'quality_note' => $quality === 'red' ? 'Operatör reddetti' : 'Operatör onayladı', 'checked_at' => now()])->save();
        if ($quality === 'red') {
            // A refused source leaves every desk where nothing was sent yet.
            BacklinkSource::query()->where('link_source_id', $source->id)->where('status', BacklinkSource::NONE)
                ->update(['status' => BacklinkSource::DROPPED, 'note' => 'Kaynak havuzda reddedildi']);
        }
    }

    public function render(LinkDesk $desk): View
    {
        $brands = Brand::query()->operational()->with('customer')->orderBy('name')->get(['id', 'name', 'customer_id', 'link_package', 'sector_id', 'link_alert_feed', 'link_alert_read_at']);
        $scope = fn (Builder $q): Builder => $q->when($this->brand > 0, fn (Builder $b) => $b->where('brand_id', $this->brand))
            ->whereIn('brand_id', $brands->pluck('id'));
        $rows = BacklinkSource::query()->with(['brand:id,name', 'target:id,url,title', 'pool:id,kind,apply_via,contact_email,won_count,applied_count'])
            ->tap($scope)->whereIn('status', [BacklinkSource::NONE, BacklinkSource::APPLIED, BacklinkSource::GIVEN, BacklinkSource::VERIFIED, BacklinkSource::REMOVED])
            ->where(fn (Builder $q) => $q->where('status', '!=', BacklinkSource::NONE)->orWhereNotNull('material_status'))
            ->orderBy('id')->limit(500)->get();
        $monthStart = now('Europe/Istanbul')->startOfMonth()->utc();
        $groups = [
            'ready' => $rows->filter(fn (BacklinkSource $r): bool => $r->status === BacklinkSource::NONE && $r->lane === 'masa')->values(),
            'person' => $rows->filter(fn (BacklinkSource $r): bool => $r->status === BacklinkSource::NONE && $r->lane === 'sen')->values(),
            'waiting' => $rows->where('status', BacklinkSource::APPLIED)->sortBy('sent_at')->values(),
            'won' => $rows->filter(fn (BacklinkSource $r): bool => $r->status === BacklinkSource::GIVEN
                || ($r->status === BacklinkSource::VERIFIED && $r->verified_at?->gte($monthStart))
                || ($r->status === BacklinkSource::REMOVED && $r->checked_at?->gte(now()->subDays(30))))->values(),
        ];

        $rows->load('messages');

        return view('livewire.operator.links.link-desk-page', [
            'brands' => $brands,
            'mailable' => $groups['ready']->filter(fn (BacklinkSource $r): bool => LinkMailer::mailReady($r))->count(),
            'canMail' => app(LinkMailer::class)->canSend(),
            'groups' => $groups,
            'plans' => $this->tab === 'hedefler' ? $this->plans($brands, $desk) : collect(),
            'pool' => $this->tab === 'havuz' ? $this->pool() : collect(),
            'sectors' => $this->tab === 'havuz' ? ServiceCategory::query()->whereIn('id', $brands->pluck('sector_id')->filter()->unique())->orderBy('name')->get(['id', 'name']) : collect(),
        ]);
    }

    /** @return Collection<int, array{brand: Brand, month: array<string, int>, targets: Collection<int, LinkTarget>}> */
    private function plans(Collection $brands, LinkDesk $desk): Collection
    {
        $surveys = LinkSurvey::query()->whereIn('brand_id', $brands->pluck('id'))->get()->keyBy('brand_id');
        $targets = LinkTarget::query()->whereIn('brand_id', $brands->pluck('id'))->whereIn('status', [LinkTarget::ACTIVE, LinkTarget::DONE])
            ->orderBy('status')->orderBy('rank')->get()->groupBy('brand_id');

        return $brands->when($this->brand > 0, fn (Collection $c) => $c->where('id', $this->brand))
            ->map(fn (Brand $b): array => ['brand' => $b, 'month' => $desk->month($b), 'targets' => $targets->get($b->id, collect()), 'survey' => $surveys->get($b->id),
                'magnet' => LinkMagnets::forBrand($b), 'magnetIdea' => LinkMagnets::idea($b)])->values();
    }

    /** @return Collection<int, LinkSource> */
    private function pool(): Collection
    {
        $q = trim($this->q);
        $like = DB::connection()->getDriverName() === 'pgsql' ? 'ilike' : 'like';

        return LinkSource::query()->with('sector:id,name')
            ->when($q !== '', fn (Builder $b) => $b->where(fn (Builder $w) => $w->where('name', $like, '%'.$q.'%')->orWhere('domain', $like, '%'.$q.'%')->orWhere('city', $like, '%'.$q.'%')))
            ->orderByRaw("CASE quality WHEN 'uygun' THEN 0 WHEN 'bekliyor' THEN 1 ELSE 2 END")->orderByDesc('won_count')->orderBy('name')->limit(300)->get();
    }

    private function row(int $id): BacklinkSource
    {
        return BacklinkSource::query()->with(['pool', 'brand'])->findOrFail($id);
    }
}
