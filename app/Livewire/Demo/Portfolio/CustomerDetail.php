<?php

namespace App\Livewire\Demo\Portfolio;

use App\Enums\ClientRequestStatus;
use App\Services\ClientRequests\ClientRequestReadService;
use App\Services\ClientRequests\ClientRequestUiActions;
use App\Services\ReportSnapshots\ReportSnapshotReadService;
use App\Support\Demo\ClientValueFixtures;
use App\Support\Demo\CommercialContextFixtures;
use App\Support\Demo\DemoCatalog;
use App\Support\Demo\DemoState;
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

        $contact = collect(DemoState::all()['contacts'] ?? [])->firstWhere('id', $contactId);
        if (! is_array($contact)) {
            return;
        }

        $this->contact_name = (string) ($contact['name'] ?? '');
        $this->contact_role = (string) ($contact['role'] ?? '');
        $this->contact_title_custom = ($contact['role'] ?? '') === ContactRoleOptions::OTHER
            ? (string) ($contact['title'] ?? '')
            : '';
        $this->contact_email = (string) ($contact['email'] ?? '');
        $this->contact_phone = (string) ($contact['phone'] ?? '');
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
            'customer_id' => $this->customerId,
            'name' => trim($this->contact_name),
            'role' => $this->contact_role !== '' ? $this->contact_role : null,
            'title' => $title === '—' ? null : $title,
            'email' => $this->contact_email !== '' ? trim($this->contact_email) : null,
            'phone' => $this->contact_phone !== '' ? trim($this->contact_phone) : null,
        ];

        if ($this->editingContactId) {
            DemoState::updateContact($this->editingContactId, $payload);
        } else {
            $payload['id'] = 'cc-'.substr(md5($this->contact_name.microtime(true)), 0, 8);
            DemoState::addContact($payload);
        }

        $this->closeContactForm();
        $this->tab = 'relationship';
    }

    public function deleteContact(string $contactId): void
    {
        DemoState::deleteContact($contactId);
        $this->tab = 'relationship';
    }

    public function archiveCustomer(): void
    {
        DemoState::setCustomerStatus($this->customerId, 'archived');
    }

    public function restoreCustomer(): void
    {
        DemoState::setCustomerStatus($this->customerId, 'active');
    }

    public function render(): View
    {
        $customer = DemoState::findCustomer($this->customerId) ?? DemoCatalog::customer();
        $customer = DemoState::normalizeCustomer($customer);
        $team = collect(DemoCatalog::teamMembers())->keyBy('id');

        $brands = collect(DemoState::all()['brands'] ?? [])
            ->filter(fn (array $b): bool => ($b['customer_id'] ?? '') === ($customer['id'] ?? ''))
            ->map(fn (array $b): array => DemoState::normalizeBrand($b))
            ->values();

        if ($brands->isEmpty() && ($customer['id'] ?? '') === DemoCatalog::CUSTOMER_ID) {
            $brands = collect([DemoState::normalizeBrand(DemoCatalog::brand())]);
        }

        $contacts = collect(DemoState::all()['contacts'] ?? [])
            ->filter(fn (array $c): bool => ($c['customer_id'] ?? '') === ($customer['id'] ?? ''))
            ->values();

        $findings = collect(DemoCatalog::findings())
            ->filter(fn (array $f): bool => ($customer['id'] ?? '') === DemoCatalog::CUSTOMER_ID)
            ->values();

        $recommendations = collect(DemoState::all()['recommendations'] ?? [])
            ->filter(fn (array $r): bool => ($customer['id'] ?? '') === DemoCatalog::CUSTOMER_ID)
            ->values();

        $tasks = collect(DemoState::all()['tasks'] ?? [])
            ->filter(fn (array $t): bool => ($customer['id'] ?? '') === DemoCatalog::CUSTOMER_ID)
            ->values();

        $openTasks = $tasks->filter(fn (array $t): bool => ! in_array($t['status'] ?? '', ['completed', 'cancelled'], true));
        $overdueTasks = $openTasks->filter(fn (array $t): bool => ($t['priority'] ?? '') === 'high' || str_contains(mb_strtolower((string) ($t['due'] ?? '')), 'overdue'));
        $attentionFindings = $findings->filter(fn (array $f): bool => in_array($f['severity'] ?? '', ['critical', 'high'], true))->take(3);

        $activity = collect(DemoState::all()['customer_activity'] ?? [])
            ->filter(fn (array $a): bool => ($a['customer_id'] ?? '') === ($customer['id'] ?? ''))
            ->when($this->activityFilter !== 'all', fn ($c) => $c->filter(fn (array $a): bool => ($a['category'] ?? '') === $this->activityFilter))
            ->values();

        $industryLabel = IndustryOptions::label($customer['industry'] ?? null);
        if (($customer['industry'] ?? '') === IndustryOptions::OTHER && ! empty($customer['industry_other'])) {
            $industryLabel = (string) $customer['industry_other'];
        }

        $digitalAssetsCount = (int) $brands->sum(fn (array $b): int => (int) ($b['assets_count'] ?? 0));
        if ($digitalAssetsCount === 0 && ($customer['id'] ?? '') === DemoCatalog::CUSTOMER_ID) {
            $digitalAssetsCount = count(DemoCatalog::assets());
        }

        // Prompt 42: production Client Requests only (no Demo fallback).
        // Resolve by the route customerId when it is a production numeric ID — do not
        // inherit DemoCatalog customer fallback identity for Request tenancy.
        $requests = [];
        if (ctype_digit((string) $this->customerId)) {
            $requests = app(ClientRequestReadService::class)
                ->forCustomerPresentation((int) $this->customerId);
        }

        return view('livewire.demo.portfolio.customer-detail', [
            'customer' => $customer,
            'industryLabel' => $industryLabel,
            'hqDisplay' => CountryOptions::formatHq($customer['hq_city'] ?? null, $customer['hq_country'] ?? null),
            'typeLabel' => ($customer['type'] ?? '') === 'individual' ? 'Individual' : 'Company',
            'statusLabel' => match ($customer['status'] ?? '') {
                'active' => 'Active',
                'inactive' => 'Inactive',
                'archived' => 'Archived',
                default => ucfirst((string) ($customer['status'] ?? '')),
            },
            'serviceLabels' => AgencyServiceOptions::labels($customer['services'] ?? []),
            'responsibleUsers' => collect($customer['responsible_user_ids'] ?? [])
                ->map(fn (string $id) => $team[$id] ?? null)
                ->filter()
                ->values()
                ->all(),
            'brands' => $brands->all(),
            'contacts' => $contacts->all(),
            'findings' => $findings->all(),
            'recommendations' => $recommendations->all(),
            'tasks' => $tasks->all(),
            'openTasks' => $openTasks->values()->all(),
            'overdueTasks' => $overdueTasks->values()->all(),
            'attentionFindings' => $attentionFindings->values()->all(),
            'activity' => $activity->take(12)->all(),
            'digitalAssetsCount' => $digitalAssetsCount,
            'openFindingsCount' => (int) ($customer['open_findings'] ?? $findings->count()),
            'openTasksCount' => (int) ($customer['open_tasks'] ?? $openTasks->count()),
            'roleOptions' => ContactRoleOptions::options(),
            'team' => $team,
            'serviceScope' => CommercialContextFixtures::serviceScopeForCustomer((string) ($customer['id'] ?? '')),
            'clientRequests' => $requests,
            'customerReports' => ctype_digit((string) $this->customerId)
                ? app(ReportSnapshotReadService::class)->forCustomerReportsPresentation((int) $this->customerId)
                : [
                    'customer_id' => $this->customerId,
                    'snapshots' => [],
                    'brands' => ClientValueFixtures::customerReports((string) ($customer['id'] ?? $this->customerId))['brands'] ?? [],
                    'empty' => true,
                    'aggregation_note' => __('operator.reports.no_blind_aggregation'),
                    'demo' => ($customer['id'] ?? '') === DemoCatalog::CUSTOMER_ID,
                    'fake_reports' => false,
                    'production_note' => __('operator.reports.demo_customer_no_snapshots'),
                ],
            'flash' => DemoState::pullFlash(),
        ]);
    }
}
