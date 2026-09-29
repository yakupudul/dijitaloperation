<?php

namespace App\Livewire\Operator\Integrations;

use App\Jobs\RefreshBrandCandidatesJob;
use App\Models\BrandCandidate;
use App\Models\BrandCandidateResource;
use App\Models\Customer;
use App\Models\ServiceCategory;
use App\Services\Ownership\OwnershipIntegrity;
use App\Services\Portfolio\BrandCandidateManager;
use App\Services\Portfolio\PortfolioDiscoveryGrouper;
use App\Services\SeoTasks\SeoText;
use App\Support\Roles;
use Illuminate\Contracts\View\View;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;
use Throwable;

/**
 * Entegrasyonlar › Keşfedilen varlıklar: every discovered account and website (bound or not), brand candidates
 * (BrandCandidateBuilder: deterministic grouping + one AI call per batch, sector proposal) with Onayla (customer →
 * brand with sector → assets) · Düzenle (name, sector, move a member) · Yoksay, and ownership problems with a safe
 * fix. Sector lives on the brand only.
 */
#[Layout('operator.layouts.app')]
#[Title('Keşfedilen varlıklar')]
final class DiscoveredAssetsPage extends Component
{
    use WithPagination;

    #[Url]
    public string $search = '';

    #[Url]
    public string $kind = '';

    #[Url]
    public string $bound = '';

    /** @var array<int|string, string> candidate id => customer id ('' = new customer) */
    public array $customerFor = [];

    /** @var array<int|string, string> candidate id => new customer name ('' = the brand name) */
    public array $customerName = [];

    /** @var array<int|string, string> candidate id => edited name */
    public array $names = [];

    /** @var array<int|string, string> candidate id => sector id */
    public array $sectorFor = [];

    /** @var array<int|string, string> member id => target candidate id ('new' = own candidate) */
    public array $moveTo = [];

    public ?int $editing = null;

    public string $message = '';

    public function updated(string $property): void
    {
        if (in_array($property, ['search', 'kind', 'bound'], true)) {
            $this->resetPage();
        }
    }

    public function regroup(): void
    {
        $this->authorizeAdmin();
        RefreshBrandCandidatesJob::dispatch();
        $this->message = 'Gruplama başladı.';
    }

    public function approve(int $candidateId, BrandCandidateManager $manager): void
    {
        $this->authorizeAdmin();
        $candidate = $this->candidate($candidateId);
        $customerId = $this->customerFor[$candidateId] ?? '';
        try {
            $outcome = $manager->approve($candidate, [
                'customer_id' => $customerId !== '' ? (int) $customerId : null,
                'customer_name' => $this->customerName[$candidateId] ?? '',
                'brand_name' => $this->names[$candidateId] ?? $candidate->name,
            ], auth()->user());
        } catch (ValidationException $exception) {
            $this->message = (string) collect($exception->errors())->flatten()->first();

            return;
        } catch (Throwable $exception) {
            report($exception);
            $this->message = 'Oluşturulamadı.';

            return;
        }
        $failed = collect($outcome['results'])->where('ok', false)->count();
        $this->message = $outcome['brand']->name.' hazır'.($failed > 0 ? ' · '.$failed.' hata' : '').'.';
    }

    public function edit(int $candidateId): void
    {
        $this->editing = $this->editing === $candidateId ? null : $candidateId;
    }

    public function saveEdit(int $candidateId, BrandCandidateManager $manager): void
    {
        $this->authorizeAdmin();
        $candidate = $this->candidate($candidateId);
        try {
            $manager->rename($candidate, (string) ($this->names[$candidateId] ?? $candidate->name));
            $sector = $this->sectorFor[$candidateId] ?? '';
            if ($sector !== (string) $candidate->sector_id) {
                $manager->setSector($candidate, $sector !== '' ? (int) $sector : null);
            }
            $this->message = 'Kaydedildi.';
        } catch (ValidationException $exception) {
            $this->message = (string) collect($exception->errors())->flatten()->first();
        }
    }

    public function move(int $memberId, BrandCandidateManager $manager): void
    {
        $this->authorizeAdmin();
        $member = BrandCandidateResource::query()->findOrFail($memberId);
        $target = $this->moveTo[$memberId] ?? '';
        if ($target === '') {
            return;
        }
        try {
            $manager->move($member, $target === 'new' ? null : $this->candidate((int) $target));
            $this->message = 'Taşındı.';
        } catch (ValidationException $exception) {
            $this->message = (string) collect($exception->errors())->flatten()->first();
        }
    }

    public function dismiss(int $candidateId, BrandCandidateManager $manager): void
    {
        $this->authorizeAdmin();
        try {
            $manager->dismiss($this->candidate($candidateId), auth()->user());
            $this->message = 'Yoksayıldı.';
        } catch (ValidationException $exception) {
            $this->message = (string) collect($exception->errors())->flatten()->first();
        }
    }

    private function candidate(int $id): BrandCandidate
    {
        return BrandCandidate::query()->findOrFail($id);
    }

    public function fixIntegrity(OwnershipIntegrity $integrity): void
    {
        $this->authorizeAdmin();
        $this->message = $integrity->fix(auth()->user()).' bağlantı kapatıldı.';
    }

    public function render(PortfolioDiscoveryGrouper $grouper, OwnershipIntegrity $integrity): View
    {
        $all = $grouper->subjects();
        $needle = SeoText::fold($this->search);
        $filtered = $all->filter(fn (array $s): bool => ($this->kind === '' || $s['kind'] === $this->kind)
            && ($this->bound === '' || ($this->bound === 'yes') === $s['bound'])
            && ($needle === '' || str_contains(SeoText::fold($s['name'].' '.$s['host'].' '.$s['brand']), $needle)))
            ->sortBy([fn (array $a, array $b): int => $a['bound'] <=> $b['bound'], fn (array $a, array $b): int => strcmp($a['name'], $b['name'])])->values();
        $page = max(1, $this->getPage());
        $rows = new LengthAwarePaginator($filtered->forPage($page, 50)->values(), $filtered->count(), 50, $page, ['path' => request()->url()]);
        $candidates = BrandCandidate::query()->where('status', BrandCandidate::PROPOSED)
            ->with(['sector', 'members.resource', 'members.website'])->orderByDesc('confidence')->orderBy('name')->limit(100)->get();
        foreach ($candidates as $candidate) {
            $this->names[$candidate->id] ??= (string) $candidate->name;
            $this->sectorFor[$candidate->id] ??= $candidate->sector_id !== null ? (string) $candidate->sector_id : '';
        }

        return view('livewire.operator.integrations.discovered-assets-page', [
            'rows' => $rows,
            'total' => $all->count(),
            'unbound' => $all->where('bound', false)->count(),
            'candidates' => $candidates,
            'sectors' => ServiceCategory::query()->orderBy('name')->pluck('name', 'id')->all(),
            'decided' => BrandCandidate::query()->where('status', '!=', BrandCandidate::PROPOSED)->count(),
            'problems' => $integrity->problems(),
            'customers' => Customer::query()->orderBy('name')->pluck('name', 'id')->all(),
            'kinds' => ['website' => 'Web sitesi', 'search_console' => 'Search Console', 'ga4' => 'GA4', 'google_business_profile' => 'İşletme Profili', 'google_ads' => 'Google Ads', 'meta_ads' => 'Meta'],
            'isAdmin' => (bool) auth()->user()?->hasRole(Roles::ADMIN),
        ]);
    }

    private function authorizeAdmin(): void
    {
        abort_unless(auth()->user()?->is_active && auth()->user()?->hasRole(Roles::ADMIN), 403);
    }
}
