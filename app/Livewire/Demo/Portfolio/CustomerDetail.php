<?php

namespace App\Livewire\Demo\Portfolio;

use App\Enums\ClientRequestStatus;
use App\Enums\CustomerStatus;
use App\Models\Customer;
use App\Models\CustomerContact;
use App\Services\ClientRequests\ClientRequestReadService;
use App\Services\ClientRequests\ClientRequestUiActions;
use App\Services\Findings\FindingReadService;
use App\Services\Operator\OperatorPortfolioPresenter;
use App\Services\Operator\OperatorUserDirectory;
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
#[Title('Customer')]
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

    #[Url(as: 'activity_filter', history: true)]
    public string $activityFilter = 'all';

    public string $taskCreateNonce = '';

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
        $legacy = [
            'contacts' => 'relationship',
            'files' => 'overview',
            'operations' => 'overview',
            'activity' => 'overview',
        ];
        if (isset($legacy[$this->tab])) {
            $this->tab = $legacy[$this->tab];
        }
        if (! in_array($this->tab, ['overview', 'brands', 'relationship', 'requests', 'reports'], true)) {
            $this->tab = 'overview';
        }
    }

    public function triageRequest(string $id): void
    {
        $this->mutateRequestStatus($id, ClientRequestStatus::Triaged);
    }

    public function planRequest(string $id): void
    {
        $this->mutateRequestStatus($id, ClientRequestStatus::Planned);
    }

    public function waitRequest(string $id): void
    {
        $this->mutateRequestStatus($id, ClientRequestStatus::WaitingOnClient);
    }

    public function doneRequest(string $id): void
    {
        $this->mutateRequestStatus($id, ClientRequestStatus::Done);
    }

    public function declineRequest(string $id): void
    {
        $this->mutateRequestStatus($id, ClientRequestStatus::Declined);
    }

    public function createTaskFromRequest(string $id): void
    {
        $result = app(ClientRequestUiActions::class)->createTask(
            $id,
            auth()->user(),
            'cr-task:'.$id.':'.$this->taskCreateNonce,
        );
        DemoState::flash(($result['message'] ?? '').($result['ok'] ? '' : ''));
        if ($result['ok']) {
            $this->taskCreateNonce = (string) Str::uuid();
        }
    }

    private function mutateRequestStatus(string $id, ClientRequestStatus $status): void
    {
        $result = app(ClientRequestUiActions::class)->changeStatus($id, $status, auth()->user());
        DemoState::flash($result['message'] ?? '');
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
        $this->contact_role = '';
        $this->contact_title_custom = (string) ($contact->title ?? '');
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
        $this->tab = 'relationship';
    }

    public function deleteContact(string $contactId): void
    {
        abort_unless(ctype_digit($contactId), 404);
        CustomerContact::query()
            ->where('customer_id', $this->customerId)
            ->whereKey((int) $contactId)
            ->delete();
        DemoState::flash(__('operator.flash.contact_removed'));
        $this->tab = 'relationship';
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

        $brands = $model->brands
            ->map(fn ($brand): array => OperatorPortfolioPresenter::brand($brand))
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

        $requests = app(ClientRequestReadService::class)->forCustomerPresentation($model->id);

        return view('livewire.demo.portfolio.customer-detail', [
            'customer' => $customer,
            'industryLabel' => $industryLabel,
            'hqDisplay' => CountryOptions::formatHq($customer['hq_city'] ?? null, $customer['hq_country'] ?? null),
            'typeLabel' => ($customer['type'] ?? '') === 'individual' ? 'Individual' : 'Company',
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
            'activity' => [],
            'digitalAssetsCount' => $digitalAssetsCount,
            'openFindingsCount' => $findings->count(),
            'openTasksCount' => $openTasks->count(),
            'roleOptions' => ContactRoleOptions::options(),
            'team' => $team,
            'serviceScope' => app(CustomerServiceScopeReadService::class)->forCustomer($model, includeEnded: false),
            'clientRequests' => $requests,
            'customerReports' => app(ReportSnapshotReadService::class)->forCustomerReportsPresentation($model->id),
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
