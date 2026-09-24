<?php

namespace App\Livewire\Demo\Portfolio;

use App\Enums\CustomerStatus;
use App\Models\Customer;
use App\Models\CustomerContact;
use App\Services\Findings\FindingReadService;
use App\Services\Operator\BrandWorkspaceReadService;
use App\Services\Operator\OperatorPortfolioPresenter;
use App\Services\Operator\OperatorUserDirectory;
use App\Services\Portfolio\CustomerCommercialSummary;
use App\Services\Recommendations\RecommendationReadService;
use App\Services\ReportSnapshots\ReportSnapshotReadService;
use App\Services\ServiceScope\CustomerServiceScopeReadService;
use App\Services\Work\WorkReadService;
use App\Support\Demo\DemoState;
use App\Support\Findings\Dto\FindingReadDto;
use App\Support\Options\AgencyServiceOptions;
use App\Support\Options\ContactRoleOptions;
use App\Support\Options\CountryOptions;
use App\Support\Options\IndustryOptions;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

#[Layout('operator.layouts.app')]
#[Title('Müşteri')]
class CustomerDetail extends Component
{
    public string $customerId = '';

    #[Url(history: true)]
    public string $tab = 'overview';

    public bool $showContactForm = false;

    public ?string $editingContactId = null;

    public string $contact_name = '';

    public string $contact_role = '';

    public string $contact_title_custom = '';

    public string $contact_email = '';

    public string $contact_phone = '';

    public string $taskCreateNonce = '';

    /** @var array{monthly_fee: string, ad_budget_google: string, ad_budget_meta: string} */
    public array $commercial = ['monthly_fee' => '', 'ad_budget_google' => '', 'ad_budget_meta' => ''];

    public bool $editingCommercial = false;

    public function mount(string $customerId): void
    {
        abort_unless(ctype_digit($customerId), 404);
        abort_if(Customer::query()->find($customerId) === null, 404);

        $this->customerId = $customerId;
        $this->taskCreateNonce = (string) Str::uuid();
        $this->normalizeTab();
    }

    public function setTab(string $tab): void
    {
        $this->tab = $tab;
        $this->normalizeTab();
    }

    private function normalizeTab(): void
    {
        // Brands, contacts and relationship live on the overview; old deep links keep working.
        if (in_array($this->tab, ['contacts', 'relationship', 'brands', 'files', 'operations', 'activity', 'requests'], true)) {
            $this->tab = 'overview';
        }
        if (! in_array($this->tab, ['overview', 'reports'], true)) {
            $this->tab = 'overview';
        }
    }

    public function editCommercial(): void
    {
        $customer = Customer::query()->findOrFail((int) $this->customerId);
        $value = static fn ($v): string => $v !== null ? rtrim(rtrim(number_format((float) $v, 2, '.', ''), '0'), '.') : '';
        $this->commercial = ['monthly_fee' => $value($customer->monthly_fee), 'ad_budget_google' => $value($customer->ad_budget_google), 'ad_budget_meta' => $value($customer->ad_budget_meta)];
        $this->editingCommercial = true;
    }

    /** Monthly fee and the ad budgets agreed with the customer (TRY); empty = not agreed. */
    public function saveCommercial(): void
    {
        // Turkish input: "15.000" or "15.000,50" means fifteen thousand; "2000,5" uses a decimal comma.
        $this->commercial = array_map(static function ($v): string {
            $v = str_replace([' ', '₺'], '', trim((string) $v));
            if (preg_match('/^\d{1,3}(\.\d{3})+(,\d+)?$/', $v) === 1) {
                $v = str_replace('.', '', $v);
            }

            return str_replace(',', '.', $v);
        }, $this->commercial);
        $this->validate([
            'commercial.monthly_fee' => ['nullable', 'numeric', 'min:0', 'max:9999999999'],
            'commercial.ad_budget_google' => ['nullable', 'numeric', 'min:0', 'max:9999999999'],
            'commercial.ad_budget_meta' => ['nullable', 'numeric', 'min:0', 'max:9999999999'],
        ]);
        Customer::query()->whereKey((int) $this->customerId)->update(array_map(static fn (string $v): ?string => $v === '' ? null : $v, $this->commercial));
        $this->editingCommercial = false;
    }

    public function openContactForm(?string $contactId = null): void
    {
        $this->editingContactId = $contactId;
        $this->showContactForm = true;
        $this->resetValidation();

        if ($contactId === null) {
            $this->contact_name = '';
            $this->contact_role = '';
            $this->contact_title_custom = '';
            $this->contact_email = '';
            $this->contact_phone = '';

            return;
        }

        abort_unless(ctype_digit($contactId), 404);
        $contact = CustomerContact::query()
            ->where('customer_id', $this->customerId)
            ->find($contactId);
        if ($contact === null) {
            return;
        }

        $this->contact_name = (string) $contact->name;
        $title = (string) ($contact->title ?? '');
        $role = $title !== '' ? array_search($title, ContactRoleOptions::options(), true) : false;
        $this->contact_role = is_string($role) ? $role : ($title !== '' ? ContactRoleOptions::OTHER : '');
        $this->contact_title_custom = is_string($role) ? '' : $title;
        $this->contact_email = (string) ($contact->email ?? '');
        $this->contact_phone = (string) ($contact->phone ?? '');
    }

    public function closeContactForm(): void
    {
        $this->showContactForm = false;
        $this->editingContactId = null;
    }

    public function saveContact(): void
    {
        $this->validate([
            'contact_name' => ['required', 'string', 'min:2', 'max:120'],
            'contact_role' => ['nullable', Rule::in(array_keys(ContactRoleOptions::options()))],
            'contact_title_custom' => ['nullable', 'string', 'max:120'],
            'contact_email' => ['nullable', 'email', 'max:180'],
            'contact_phone' => ['nullable', 'string', 'max:60'],
        ], [], [
            'contact_name' => 'name',
            'contact_role' => 'role',
            'contact_email' => 'email',
            'contact_phone' => 'phone',
        ]);

        $title = $this->contact_role === ContactRoleOptions::OTHER && $this->contact_title_custom !== ''
            ? trim($this->contact_title_custom)
            : ContactRoleOptions::label($this->contact_role !== '' ? $this->contact_role : null);

        $payload = [
            'customer_id' => (int) $this->customerId,
            'name' => trim($this->contact_name),
            'title' => $title === '—' ? null : $title,
            'email' => $this->contact_email !== '' ? trim($this->contact_email) : null,
            'phone' => $this->contact_phone !== '' ? trim($this->contact_phone) : null,
        ];

        if ($this->editingContactId && ctype_digit($this->editingContactId)) {
            CustomerContact::query()
                ->where('customer_id', $this->customerId)
                ->whereKey((int) $this->editingContactId)
                ->update($payload);
            DemoState::flash(__('operator.flash.contact_updated'));
        } else {
            CustomerContact::query()->create($payload);
            DemoState::flash(__('operator.flash.contact_saved'));
        }

        $this->closeContactForm();
        $this->tab = 'overview';
    }

    public function deleteContact(string $contactId): void
    {
        abort_unless(ctype_digit($contactId), 404);
        CustomerContact::query()
            ->where('customer_id', $this->customerId)
            ->whereKey((int) $contactId)
            ->delete();
        DemoState::flash(__('operator.flash.contact_removed'));
        $this->tab = 'overview';
    }

    public function archiveCustomer(): void
    {
        $customer = $this->canonicalCustomer();
        $customer->status = CustomerStatus::Archived;
        $customer->save();
        DemoState::flash(__('operator.flash.customer_archived'));
    }

    public function restoreCustomer(): void
    {
        $customer = $this->canonicalCustomer();
        $customer->status = CustomerStatus::Active;
        $customer->save();
        DemoState::flash(__('operator.flash.customer_restored'));
    }

    public function render(): View
    {
        $model = $this->canonicalCustomer();
        $model->load(['brands.digitalAssets', 'responsibleUsers', 'contacts']);
        $customer = OperatorPortfolioPresenter::customer($model);
        $team = collect(OperatorUserDirectory::presentationMembers())->keyBy('id');

        $workspace = app(BrandWorkspaceReadService::class);
        $brands = $model->brands
            ->map(function ($brand) use ($workspace): array {
                $assets = $workspace->assets($brand);

                return OperatorPortfolioPresenter::brand($brand) + [
                    'setup' => $workspace->checklist($brand, $assets, $workspace->services($brand)),
                    'accounts' => collect($assets)->flatMap(fn (array $a): array => array_column($a['accounts'], 'label'))->unique()->values()->all(),
                ];
            })
            ->values();

        $contacts = $model->contacts
            ->map(fn (CustomerContact $contact): array => OperatorPortfolioPresenter::contact($contact))
            ->values();

        $findings = collect(app(FindingReadService::class)->forCustomer($model))
            ->map(fn (FindingReadDto $dto): array => $dto->toArray())
            ->values();

        $recommendations = app(RecommendationReadService::class)->forListPresentation(['customer_id' => $model->id]);
        $tasks = collect(app(WorkReadService::class)->workItems())
            ->filter(fn (array $t): bool => (int) ($t['customer_id'] ?? 0) === $model->id)
            ->values();

        $openTasks = $tasks->filter(fn (array $t): bool => ! in_array($t['status'] ?? '', ['completed', 'cancelled', 'done', 'declined', 'skipped'], true));
        $overdueTasks = $openTasks->filter(fn (array $t): bool => ($t['due_key'] ?? '') === 'overdue');
        $attentionFindings = $findings->filter(fn (array $f): bool => in_array($f['severity'] ?? '', ['critical', 'high'], true))->take(3);

        $industryLabel = IndustryOptions::label($customer['industry'] ?? null);
        $digitalAssetsCount = (int) $brands->sum(fn (array $b): int => (int) ($b['assets_count'] ?? 0));

        return view('livewire.demo.portfolio.customer-detail', [
            'customer' => $customer,
            'industryLabel' => $industryLabel,
            'hqDisplay' => CountryOptions::formatHq($customer['hq_city'] ?? null, $customer['hq_country'] ?? null),
            'typeLabel' => ($customer['type'] ?? '') === 'individual' ? 'Bireysel' : 'Şirket',
            'statusLabel' => $customer['status_label'] ?? '',
            'serviceLabels' => AgencyServiceOptions::labels($customer['services'] ?? []),
            'responsibleUsers' => collect($customer['responsible_user_ids'] ?? [])
                ->map(fn (string $id) => $team[$id] ?? null)
                ->filter()
                ->values()
                ->all(),
            'brands' => $brands->all(),
            'contacts' => $contacts->all(),
            'findings' => $findings->all(),
            'recommendations' => $recommendations,
            'tasks' => $tasks->all(),
            'openTasks' => $openTasks->values()->all(),
            'overdueTasks' => $overdueTasks->values()->all(),
            'attentionFindings' => $attentionFindings->values()->all(),
            'digitalAssetsCount' => $digitalAssetsCount,
            'openFindingsCount' => $findings->count(),
            'openTasksCount' => $openTasks->count(),
            'roleOptions' => ContactRoleOptions::options(),
            'team' => $team,
            'serviceScope' => app(CustomerServiceScopeReadService::class)->forCustomer($model, includeEnded: false),
            'customerReports' => app(ReportSnapshotReadService::class)->forCustomerReportsPresentation($model->id),
            'commercialSummary' => app(CustomerCommercialSummary::class)->for($model),
            'flash' => DemoState::pullFlash(),
        ]);
    }

    private function canonicalCustomer(): Customer
    {
        abort_unless(ctype_digit($this->customerId), 404);
        $customer = Customer::query()->find($this->customerId);
        abort_if($customer === null, 404);

        return $customer;
    }
}
