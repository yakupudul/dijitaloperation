<?php

namespace App\Livewire\Demo\Portfolio;

use App\Livewire\Demo\Concerns\InteractsWithDemoPeriod;
use App\Models\Brand;
use App\Models\Recommendation;
use App\Services\Approvals\ApprovalReadService;
use App\Services\BusinessOutcomes\BusinessOutcomeReadService;
use App\Services\ClientRequests\ClientRequestReadService;
use App\Services\ClientValueStory\ClientValueStoryReadService;
use App\Services\CreateTaskFromRecommendation;
use App\Services\Opportunities\OpportunityReadService;
use App\Services\RecurringReviews\RecurringReviewReadService;
use App\Support\Demo\AgencyExecutionFixtures;
use App\Support\Demo\BrandPublicDiscoveryFixtures;
use App\Support\Demo\BusinessOutcomeFixtures;
use App\Support\Demo\ClientValueFixtures;
use App\Support\Demo\CommercialContextFixtures;
use App\Support\Demo\DemoCatalog;
use App\Support\Demo\DemoPeriod;
use App\Support\Demo\DemoState;
use App\Support\Demo\OpportunityFixtures;
use App\Support\Options\CountryOptions;
use App\Support\Options\IndustryOptions;
use App\Support\Options\LanguageOptions;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Str;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

#[Layout('operator.layouts.app')]
#[Title('Brand')]
class BrandShow extends Component
{
    use InteractsWithDemoPeriod;

    public string $brand = DemoCatalog::BRAND_ID;

    #[Url]
    public string $tab = 'overview';

    #[Url(as: 'discovery')]
    public string $discovery = 'overview';

    /** Business internal segment: context | discovery */
    #[Url(as: 'business')]
    public string $businessSection = 'context';

    /** Digital Estate internal segment: assets | cross_channel */
    #[Url(as: 'estate')]
    public string $estateSection = 'assets';

    /** Value internal segment: overview | story | outcomes | decisions | reports */
    #[Url(as: 'value')]
    public string $valueSection = 'overview';

    #[Url]
    public string $ops = 'findings';

    #[Url]
    public string $asset_q = '';

    #[Url]
    public string $asset_type = '';

    #[Url]
    public string $asset_connection = '';

    #[Url]
    public string $asset_attention = '';

    #[Url]
    public string $website_id = '';

    #[Url]
    public string $history_asset = '';

    #[Url]
    public string $history_type = '';

    public bool $editingContext = false;

    public string $context_business_summary = '';

    public string $context_business_model = '';

    public string $context_priority_offerings = '';

    public string $context_target_audiences = '';

    public string $context_positioning = '';

    public string $context_differentiators = '';

    public string $context_business_goals = '';

    public string $context_conversion_goals = '';

    public string $context_constraints = '';

    public ?string $reviewCandidateId = null;

    public ?string $reviewConflictId = null;

    public string $ignoreReason = 'irrelevant';

    public string $outcome_platform_leads = '';

    public string $outcome_qualified_leads = '';

    public string $outcome_consultations = '';

    public string $outcome_patients = '';

    public string $outcome_note = '';

    public bool $showOutcomeForm = false;

    public string $reportLanguage = 'en';

    public string $reportTone = 'client';

    public string $reportOperatorNote = '';

    public string $taskCreateNonce = '';

    /** @var array<string, bool> */
    public array $reportSections = [];

    /**
     * @var list<string>
     */
    private const AI_PRIORITY_RECOMMENDATION_IDS = [
        'r-replace-creative',
        'r-fix-lcp',
        'r-negatives',
        'r-map-relevance',
    ];

    /**
     * Final Brand primary IA (Milestone 1 pruning).
     *
     * @var list<string>
     */
    private const TABS = [
        'overview',
        'business',
        'estate',
        'growth',
        'operations',
        'value',
    ];

    /**
     * Old primary tabs → final containers (preserve deep links).
     *
     * @var array<string, string>
     */
    private const LEGACY_TAB_MAP = [
        'research' => 'business',
        'discovery' => 'business',
        'context' => 'business',
        'assets' => 'estate',
        'cross_channel' => 'estate',
        'ai' => 'growth',
        'history' => 'value',
        'files' => 'overview',
    ];

    public function mount(string $brand): void
    {
        $this->brand = $brand;
        $this->taskCreateNonce = (string) Str::uuid();
        $this->normalizeTab();
        $this->mountPeriod();
        $this->hydrateOutcomeForm();
        $this->hydrateReportComposer();
    }

    public function setValueSection(string $section): void
    {
        if (in_array($section, ClientValueFixtures::valueSections(), true)) {
            $this->valueSection = $section;
        }
        $this->tab = 'value';
    }

    public function hydrateReportComposer(): void
    {
        $saved = DemoState::reportConfig();
        $this->reportLanguage = (string) ($saved['language'] ?? 'en');
        $this->reportTone = (string) ($saved['tone'] ?? 'client');
        $this->reportOperatorNote = (string) ($saved['operator_note'] ?? '');
        $defaults = array_fill_keys(ClientValueFixtures::reportSectionKeys(), true);
        $sections = is_array($saved['sections'] ?? null) ? $saved['sections'] : [];
        $this->reportSections = array_merge($defaults, array_map('boolval', $sections));
    }

    public function toggleReportSection(string $key): void
    {
        if (! array_key_exists($key, $this->reportSections)) {
            return;
        }
        $this->reportSections[$key] = ! $this->reportSections[$key];
        $this->persistReportComposer();
    }

    public function setReportLanguage(string $language): void
    {
        if (in_array($language, ['en', 'tr'], true)) {
            $this->reportLanguage = $language;
            $this->persistReportComposer();
        }
    }

    public function setReportTone(string $tone): void
    {
        if (in_array($tone, ['client', 'internal'], true)) {
            $this->reportTone = $tone;
            $this->persistReportComposer();
        }
    }

    public function refreshReportPreview(): void
    {
        $this->persistReportComposer();
    }

    private function persistReportComposer(): void
    {
        DemoState::setReportConfig([
            'period' => $this->period,
            'language' => $this->reportLanguage,
            'tone' => $this->reportTone,
            'operator_note' => $this->reportOperatorNote,
            'sections' => $this->reportSections,
        ]);
    }

    private function hydrateOutcomeForm(): void
    {
        if ($this->brand !== DemoCatalog::BRAND_ID) {
            return;
        }

        $period = (string) (DemoState::all()['period_preset'] ?? 'last_28');
        $outcomes = DemoState::businessOutcomes($period);
        $this->outcome_platform_leads = (string) ($outcomes['platform_leads'] ?? '');
        $this->outcome_qualified_leads = (string) ($outcomes['qualified_leads'] ?? '');
        $this->outcome_consultations = (string) ($outcomes['consultations'] ?? '');
        $this->outcome_patients = (string) ($outcomes['patients'] ?? '');
        $this->outcome_note = (string) ($outcomes['note'] ?? '');
    }

    public function openOutcomeForm(): void
    {
        $this->hydrateOutcomeForm();
        $this->showOutcomeForm = true;
        $this->tab = 'value';
    }

    public function cancelOutcomeForm(): void
    {
        $this->showOutcomeForm = false;
    }

    public function saveBusinessOutcomes(): void
    {
        // Prompt 57: Demo session overrides are retired for Business Outcome cards.
        // Aggregate Business Outcomes persist via BusinessOutcomeObservationService only.
        if ($this->brand !== DemoCatalog::BRAND_ID) {
            return;
        }

        $this->showOutcomeForm = false;
        $this->tab = 'value';
        session()->flash('status', 'Business Outcomes require production Brand persistence (manual/CSV). Demo fake values are retired.');
    }

    public function setTab(string $tab): void
    {
        $this->tab = $tab;
        $this->normalizeTab();
    }

    public function setBusinessSection(string $section): void
    {
        if (in_array($section, ['context', 'discovery'], true)) {
            $this->businessSection = $section;
            $this->tab = 'business';
            if ($section === 'discovery') {
                $this->discovery = in_array($this->discovery, ['overview', 'facts', 'candidates', 'conflicts', 'sources'], true)
                    ? $this->discovery
                    : 'overview';
            }
        }
    }

    public function setEstateSection(string $section): void
    {
        if (in_array($section, ['assets', 'cross_channel'], true)) {
            $this->estateSection = $section;
            $this->tab = 'estate';
        }
    }

    public function setDiscovery(string $section): void
    {
        if (in_array($section, ['overview', 'facts', 'candidates', 'conflicts', 'sources'], true)) {
            $this->discovery = $section;
            $this->tab = 'business';
            $this->businessSection = 'discovery';
            $this->reviewCandidateId = null;
            $this->reviewConflictId = null;
        }
    }

    public function setOps(string $ops): void
    {
        $allowed = ['findings', 'opportunities', 'recommendations', 'work', 'requests', 'approvals', 'reviews', 'tasks', 'outcomes'];
        if (in_array($ops, $allowed, true)) {
            $this->ops = $ops === 'tasks' ? 'work' : $ops;
            $this->tab = 'operations';
        }
    }

    private function normalizeTab(): void
    {
        if (in_array($this->tab, ['findings', 'recommendations', 'tasks'], true)) {
            $this->ops = $this->tab;
            $this->tab = 'operations';
        }

        if (isset(self::LEGACY_TAB_MAP[$this->tab])) {
            $legacy = $this->tab;
            $this->tab = self::LEGACY_TAB_MAP[$legacy];
            if ($legacy === 'discovery' || $legacy === 'research') {
                $this->businessSection = 'discovery';
            }
            if ($legacy === 'context') {
                $this->businessSection = 'context';
            }
            if ($legacy === 'cross_channel') {
                $this->estateSection = 'cross_channel';
            }
            if ($legacy === 'assets') {
                $this->estateSection = 'assets';
            }
        }

        if (! in_array($this->tab, self::TABS, true)) {
            $this->tab = 'overview';
        }

        if (! in_array($this->ops, ['findings', 'opportunities', 'recommendations', 'work', 'requests', 'approvals', 'reviews', 'tasks', 'outcomes'], true)) {
            $this->ops = 'findings';
        }
        if ($this->ops === 'tasks') {
            $this->ops = 'work';
        }

        if (! in_array($this->discovery, ['overview', 'facts', 'candidates', 'conflicts', 'sources'], true)) {
            $this->discovery = 'overview';
        }

        if (! in_array($this->businessSection, ['context', 'discovery'], true)) {
            $this->businessSection = 'context';
        }

        if (! in_array($this->estateSection, ['assets', 'cross_channel'], true)) {
            $this->estateSection = 'assets';
        }
        if (! in_array($this->valueSection, ClientValueFixtures::valueSections(), true)) {
            $this->valueSection = 'overview';
        }
    }

    public function clearAssetFilters(): void
    {
        $this->asset_q = '';
        $this->asset_type = '';
        $this->asset_connection = '';
        $this->asset_attention = '';
    }

    public function runPublicResearch(): void
    {
        DemoState::startPublicResearch();
        $this->tab = 'business';
        $this->businessSection = 'discovery';
        $this->discovery = 'overview';
    }

    public function openCandidate(string $id): void
    {
        $this->reviewCandidateId = $id;
        $this->discovery = 'candidates';
        $this->tab = 'business';
        $this->businessSection = 'discovery';
    }

    public function closeCandidate(): void
    {
        $this->reviewCandidateId = null;
    }

    public function openConflict(string $id): void
    {
        $this->reviewConflictId = $id;
        $this->discovery = 'conflicts';
        $this->tab = 'business';
        $this->businessSection = 'discovery';
    }

    public function closeConflict(): void
    {
        $this->reviewConflictId = null;
    }

    public function closeDrawers(): void
    {
        $this->reviewCandidateId = null;
        $this->reviewConflictId = null;
    }

    public function startEditingContext(): void
    {
        $this->tab = 'business';
        $this->businessSection = 'context';
        $this->editingContext = true;
        $context = $this->resolveBusinessContext($this->findBrandRow() ?? []);
        $this->context_business_summary = (string) ($context['business_summary'] ?? '');
        $this->context_business_model = (string) ($context['business_model'] ?? '');
        $this->context_priority_offerings = implode(', ', array_values($context['priority_offerings'] ?? []));
        $this->context_target_audiences = implode(', ', array_values($context['target_audiences'] ?? []));
        $this->context_positioning = (string) ($context['positioning'] ?? '');
        $this->context_differentiators = implode(', ', array_values($context['differentiators'] ?? []));
        $this->context_business_goals = implode(', ', array_values($context['business_goals'] ?? []));
        $this->context_conversion_goals = implode(', ', array_values($context['conversion_goals'] ?? []));
        $this->context_constraints = implode(', ', array_values($context['important_constraints'] ?? []));
    }

    public function cancelEditingContext(): void
    {
        $this->editingContext = false;
    }

    public function saveBusinessContext(): void
    {
        $brandId = $this->brand;
        $split = static function (string $value): array {
            return array_values(array_filter(array_map('trim', preg_split('/[,\\n]+/', $value) ?: [])));
        };

        $priority = $split($this->context_priority_offerings);
        $audiences = $split($this->context_target_audiences);
        $differentiators = $split($this->context_differentiators);
        $goals = $split($this->context_business_goals);
        $conversions = $split($this->context_conversion_goals);
        $constraints = $split($this->context_constraints);

        $base = $this->resolveBusinessContext($this->findBrandRow() ?? []);
        $completed = 0;
        foreach ([$this->context_business_summary, $this->context_business_model, $this->context_positioning] as $scalar) {
            if (trim($scalar) !== '') {
                $completed++;
            }
        }
        foreach ([$priority, $audiences, $goals, $conversions, $constraints] as $list) {
            if ($list !== []) {
                $completed++;
            }
        }

        DemoState::saveBrandBusinessContext($brandId, [
            'completed' => min(8, $completed),
            'total' => 8,
            'business_summary' => trim($this->context_business_summary) !== '' ? trim($this->context_business_summary) : null,
            'business_model' => trim($this->context_business_model) !== '' ? trim($this->context_business_model) : null,
            'products_services' => $priority !== [] ? $priority : ($base['products_services'] ?? []),
            'priority_offerings' => $priority,
            'target_audiences' => $audiences,
            'target_markets' => $base['target_markets'] ?? [],
            'business_goals' => $goals,
            'conversion_goals' => $conversions,
            'positioning' => trim($this->context_positioning) !== '' ? trim($this->context_positioning) : null,
            'differentiators' => $differentiators,
            'known_competitors' => $base['known_competitors'] ?? [],
            'important_constraints' => $constraints,
            'unknown_areas' => $completed >= 8 ? [] : ['Some Business Context areas still incomplete'],
        ]);

        $this->editingContext = false;
    }

    /**
     * @param  array<string, mixed>  $brandRow
     * @return array<string, mixed>
     */
    private function resolveBusinessContext(array $brandRow): array
    {
        $saved = DemoState::brandBusinessContext((string) ($brandRow['id'] ?? $this->brand));
        if (is_array($saved) && $saved !== []) {
            return $saved;
        }

        if (($brandRow['id'] ?? '') === DemoCatalog::BRAND_ID) {
            return DemoCatalog::brandBusinessContext();
        }

        return [
            'completed' => (int) ($brandRow['context_completed'] ?? 0),
            'total' => (int) ($brandRow['context_total'] ?? 8),
            'updated_at' => null,
            'updated_by' => null,
            'source' => 'Operator maintained',
            'business_summary' => $brandRow['description'] ?? null,
            'business_model' => null,
            'products_services' => [],
            'priority_offerings' => [],
            'target_audiences' => array_filter([(string) ($brandRow['audience'] ?? '')]),
            'target_markets' => $brandRow['target_markets'] ?? [],
            'business_goals' => [],
            'conversion_goals' => [],
            'positioning' => null,
            'differentiators' => [],
            'known_competitors' => [],
            'important_constraints' => [],
            'unknown_areas' => ['Structured Business Context not started'],
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function findBrandRow(): ?array
    {
        $row = collect(DemoState::all()['brands'] ?? [])->firstWhere('id', $this->brand);

        return is_array($row) ? $row : null;
    }

    /**
     * Production Opportunities scoped to this Brand. Demo catalog brand identifiers (e.g. "atlas-dental")
     * never match a database Brand id, so non-numeric route values correctly resolve to an empty list —
     * no Demo fixture fallback on this production surface.
     *
     * @return list<array<string, mixed>>
     */
    private function brandOpportunities(): array
    {
        if (! ctype_digit($this->brand)) {
            return [];
        }

        return app(OpportunityReadService::class)->forListPresentation(['brand_id' => (int) $this->brand]);
    }

    public function acceptDiscoveryCandidate(string $id): void
    {
        DemoState::setDiscoveryCandidateStatus($id, 'accepted');
        $this->reviewCandidateId = null;
    }

    public function mapDiscoveryCandidate(string $id, string $existingLabel): void
    {
        DemoState::setDiscoveryCandidateStatus($id, 'mapped', null, $existingLabel);
        $this->reviewCandidateId = null;
    }

    public function ignoreDiscoveryCandidate(string $id): void
    {
        DemoState::setDiscoveryCandidateStatus($id, 'ignored', $this->ignoreReason !== '' ? $this->ignoreReason : 'other');
        $this->reviewCandidateId = null;
    }

    public function editAcceptDiscoveryCandidate(string $id): void
    {
        DemoState::setDiscoveryCandidateStatus($id, 'accepted');
        $this->reviewCandidateId = null;
    }

    public function resolveConflict(string $conflictId, string $decision): void
    {
        DemoState::resolveDiscoveryConflict($conflictId, $decision);
        $this->reviewConflictId = null;
    }

    public function runAiBrief(): void
    {
        DemoState::showAiBrief();
        $this->tab = 'growth';
    }

    public function createRecommendationFromPriority(int $index): void
    {
        $analysis = DemoCatalog::brandAiAnalysis();
        $priority = $analysis['priorities'][$index] ?? null;

        if (! is_string($priority) || $priority === '') {
            return;
        }

        $recommendationId = self::AI_PRIORITY_RECOMMENDATION_IDS[$index] ?? null;
        DemoState::createRecommendationFromAiPriority($priority, $recommendationId);
        $this->ops = 'recommendations';
        $this->tab = 'operations';
    }

    public function createTaskFromRecommendation(string $recommendationId): void
    {
        if (! ctype_digit($recommendationId)) {
            DemoState::flash('Only production Recommendations can create Tasks.', 'info');

            return;
        }

        $recommendation = Recommendation::query()->find((int) $recommendationId);
        if ($recommendation === null) {
            DemoState::flash('Recommendation not found.', 'info');

            return;
        }

        $actor = auth()->user();
        $service = app(CreateTaskFromRecommendation::class);
        if (! $service->userCanConvert($actor)) {
            DemoState::flash('You are not allowed to create Tasks from Recommendations.', 'info');

            return;
        }

        try {
            $nonce = $this->taskCreateNonce !== '' ? $this->taskCreateNonce : (string) Str::uuid();
            $task = $service->create(
                $recommendation,
                [],
                $actor,
                'rec-task:'.$recommendation->id.':brand:'.$nonce,
            );
            $this->taskCreateNonce = (string) Str::uuid();
            DemoState::flash('Task #'.$task->id.' created from Recommendation.');
            $this->ops = 'tasks';
            $this->tab = 'operations';
        } catch (\Throwable $exception) {
            DemoState::flash($exception->getMessage(), 'info');
        }
    }

    /**
     * @param  list<array<string, mixed>>  $assets
     * @return list<array<string, mixed>>
     */
    protected function enrichAssets(array $assets): array
    {
        return collect($assets)->map(function (array $asset): array {
            $connection = (string) ($asset['connection'] ?? '');
            $asset['connection_label'] = match ($connection) {
                'connected' => 'Connected',
                'public_plus_detected' => 'Configured',
                'detected' => 'Configured',
                'manual' => 'Configured',
                'disabled' => 'Disabled',
                '' => 'Not configured',
                default => ucfirst(str_replace('_', ' ', $connection)),
            };
            if (($asset['health'] ?? '') === 'needs_attention' || ($asset['health'] ?? '') === 'warning') {
                if ($connection === 'connected' || $connection === 'public_plus_detected') {
                    $asset['connection_label'] = 'Needs attention';
                }
            }
            $asset['freshness_label'] = match ((string) ($asset['last_update'] ?? '')) {
                '', 'Never' => 'Never collected',
                'Detected', 'Manual' => (string) $asset['last_update'],
                default => str_starts_with((string) $asset['last_update'], 'Updated')
                    ? (string) $asset['last_update']
                    : 'Updated '.((string) $asset['last_update']),
            };

            return $asset;
        })->values()->all();
    }

    /**
     * @param  list<array<string, mixed>>  $tasks
     * @param  list<array<string, mixed>>  $recommendations
     * @param  list<array<string, mixed>>  $findings
     * @return list<array<string, mixed>>
     */
    protected function buildAttentionItems(array $tasks, array $recommendations, array $findings): array
    {
        $items = [];

        foreach (DemoCatalog::brandAttention() as $row) {
            if (strtolower((string) ($row['severity'] ?? '')) === 'info') {
                continue;
            }
            $items[] = [
                'kind' => 'finding',
                'severity' => strtoupper((string) ($row['severity'] ?? 'medium')),
                'title' => $row['title'] ?? $row['issue'] ?? '',
                'where' => $row['asset'] ?? '',
                'why' => $row['why'] ?? '',
                'when' => $row['evidence'] ?? '',
                'action_label' => $row['action_label'] ?? 'Review',
                'route' => $row['route'] ?? null,
                'route_params' => $row['route_params'] ?? [],
            ];
        }

        foreach ($tasks as $task) {
            if (($task['status'] ?? '') === 'blocked') {
                $items[] = [
                    'kind' => 'blocked_task',
                    'severity' => 'BLOCKED TASK',
                    'title' => $task['title'] ?? '',
                    'where' => $task['asset'] ?? '',
                    'why' => 'Blocked work stops the operational loop.',
                    'when' => 'Assigned to: '.($task['owner'] ?? '—').' · Due '.($task['due'] ?? '—'),
                    'action_label' => 'Open task',
                    'route' => 'demo.task',
                    'route_params' => ['taskId' => $task['id'] ?? ''],
                ];
            }
            if (($task['due'] ?? '') === 'Last week' && ($task['status'] ?? '') !== 'completed') {
                $items[] = [
                    'kind' => 'overdue_task',
                    'severity' => 'OVERDUE',
                    'title' => $task['title'] ?? '',
                    'where' => $task['asset'] ?? '',
                    'why' => 'Due date has passed while work remains open.',
                    'when' => 'Assigned to: '.($task['owner'] ?? '—').' · Due '.$task['due'],
                    'action_label' => 'Open task',
                    'route' => 'demo.task',
                    'route_params' => ['taskId' => $task['id'] ?? ''],
                ];
            }
        }

        foreach (DemoCatalog::brandCrossChannel() as $check) {
            if (($check['state'] ?? '') !== 'needs_attention') {
                continue;
            }
            $items[] = [
                'kind' => 'cross_channel',
                'severity' => 'HIGH',
                'title' => $check['finding_title'] ?? $check['summary'] ?? $check['check'],
                'where' => $check['check'] ?? '',
                'why' => $check['summary'] ?? '',
                'when' => 'Detected '.$check['last_checked'],
                'action_label' => 'Review finding',
                'route' => $check['route'] ?? null,
                'route_params' => [],
                'wire_tab' => 'cross_channel',
            ];
        }

        return array_slice($items, 0, 8);
    }

    /**
     * @param  list<array<string, mixed>>  $tasks
     * @param  list<array<string, mixed>>  $recommendations
     * @return list<array<string, mixed>>
     */
    protected function buildPriorities(array $tasks, array $recommendations): array
    {
        $items = [];

        foreach ($tasks as $task) {
            if (! in_array($task['status'] ?? '', ['in_progress', 'open', 'blocked'], true)) {
                continue;
            }
            if (($task['priority'] ?? '') !== 'high' && ($task['status'] ?? '') !== 'in_progress') {
                continue;
            }
            $items[] = [
                'title' => $task['title'] ?? '',
                'kind' => 'Task · '.ucfirst(str_replace('_', ' ', (string) ($task['status'] ?? 'open'))),
                'priority' => ucfirst((string) ($task['priority'] ?? 'medium')).' priority',
                'asset' => $task['asset'] ?? '',
                'href' => route('demo.task', ['taskId' => $task['id'] ?? '']),
            ];
        }

        foreach ($recommendations as $rec) {
            if (($rec['status'] ?? '') !== 'pending') {
                continue;
            }
            $hasTask = collect($tasks)->contains(fn (array $t): bool => ($t['recommendation_id'] ?? '') === ($rec['id'] ?? ''));
            if ($hasTask) {
                continue;
            }
            $items[] = [
                'title' => $rec['title'] ?? '',
                'kind' => 'Recommendation',
                'priority' => 'Needs decision',
                'asset' => $rec['asset'] ?? '',
                'href' => route('demo.recommendations'),
            ];
        }

        return array_slice($items, 0, 5);
    }

    public function render(): View
    {
        $state = DemoState::all();
        $brandRow = collect($state['brands'])->firstWhere('id', $this->brand);
        if ($brandRow === null && $this->brand === DemoCatalog::BRAND_ID) {
            $brandRow = DemoCatalog::brand();
        }
        $brandRow = DemoState::normalizeBrand($brandRow ?? ['id' => $this->brand, 'name' => 'Unknown brand']);

        $customer = collect($state['customers'] ?? [])->firstWhere('id', $brandRow['customer_id'] ?? '')
            ?? ($this->brand === DemoCatalog::BRAND_ID ? DemoCatalog::customer() : null);

        $team = collect(DemoCatalog::teamMembers())->keyBy('id');
        $responsibleUsers = collect($brandRow['responsible_user_ids'] ?? [])
            ->map(fn (string $id) => $team[$id] ?? null)
            ->filter()
            ->values()
            ->all();

        $allAssets = array_merge(DemoCatalog::assets(), $state['demo_assets'] ?? []);
        $assets = collect($allAssets)
            ->filter(function (array $asset) use ($brandRow): bool {
                $brandId = $brandRow['id'] ?? '';

                return ($asset['brand_id'] ?? '') === $brandId
                    || ($brandId === DemoCatalog::BRAND_ID && ($asset['brand_id'] ?? DemoCatalog::BRAND_ID) === DemoCatalog::BRAND_ID);
            })
            ->reject(fn (array $asset): bool => ($asset['legacy_deprecated'] ?? false) === true
                || in_array((string) ($asset['type'] ?? ''), ['domain', 'hosting'], true))
            ->values()
            ->all();

        if (($brandRow['id'] ?? '') === DemoCatalog::BRAND_ID && $assets === []) {
            $assets = collect(DemoCatalog::assets())
                ->reject(fn (array $asset): bool => ($asset['legacy_deprecated'] ?? false) === true
                    || in_array((string) ($asset['type'] ?? ''), ['domain', 'hosting'], true))
                ->values()
                ->all();
        }

        $assets = $this->enrichAssets($assets);
        $brandName = (string) ($brandRow['name'] ?? '');

        $findings = collect(DemoCatalog::findings())
            ->filter(fn (array $f): bool => ($f['brand'] ?? '') === $brandName)
            ->values()
            ->all();

        $recommendations = collect($state['recommendations'] ?? [])
            ->filter(fn (array $r): bool => ($r['brand'] ?? '') === $brandName || $brandName === '')
            ->values()
            ->all();

        $tasks = collect($state['tasks'] ?? [])
            ->filter(fn (array $t): bool => ($t['brand'] ?? '') === $brandName || $brandName === '')
            ->values()
            ->all();

        $openFindings = collect($findings)->where('status', 'open')->count();
        $openRecommendations = collect($recommendations)->whereIn('status', ['pending', 'approved'])->count();
        $openTasks = collect($tasks)->whereIn('status', ['open', 'in_progress', 'blocked'])->count();
        $blockedOrOverdue = collect($tasks)->filter(function (array $t): bool {
            return ($t['status'] ?? '') === 'blocked' || (($t['due'] ?? '') === 'Last week' && ($t['status'] ?? '') !== 'completed');
        })->count();
        $awaitingFollowUp = collect($tasks)->filter(function (array $t): bool {
            return ($t['status'] ?? '') === 'completed' && (($t['outcome']['status'] ?? null) === null || ($t['outcome']['status'] ?? '') === 'awaiting_follow_up');
        })->count();
        $regressions = collect($tasks)->filter(fn (array $t): bool => ($t['outcome']['status'] ?? '') === 'regression')->count();

        $connectedAssets = collect($assets)->filter(function (array $a): bool {
            return in_array($a['connection'] ?? '', ['connected', 'public_plus_detected'], true);
        })->count();

        $businessContext = $this->resolveBusinessContext($brandRow);

        $crossChannel = ($brandRow['id'] ?? '') === DemoCatalog::BRAND_ID
            ? DemoCatalog::brandCrossChannel()
            : [];

        $attention = $this->buildAttentionItems($tasks, $recommendations, $findings);
        $priorities = $this->buildPriorities($tasks, $recommendations);
        $allDecisionChains = ($brandRow['id'] ?? '') === DemoCatalog::BRAND_ID
            ? DemoCatalog::brandDecisionChains()
            : [];
        $recentActivity = ($brandRow['id'] ?? '') === DemoCatalog::BRAND_ID
            ? DemoCatalog::brandRecentActivity()
            : [];

        $filteredAssets = collect($assets);
        if ($this->asset_q !== '') {
            $q = mb_strtolower($this->asset_q);
            $filteredAssets = $filteredAssets->filter(function (array $a) use ($q): bool {
                return str_contains(mb_strtolower(($a['name'] ?? '').' '.($a['type_label'] ?? '')), $q);
            });
        }
        if ($this->asset_type !== '') {
            $filteredAssets = $filteredAssets->filter(fn (array $a): bool => ($a['type'] ?? '') === $this->asset_type);
        }
        if ($this->asset_connection !== '') {
            $filteredAssets = $filteredAssets->filter(function (array $a): bool {
                $label = mb_strtolower((string) ($a['connection_label'] ?? ''));

                return match ($this->asset_connection) {
                    'connected' => str_contains($label, 'connected'),
                    'needs_attention' => str_contains($label, 'attention'),
                    'configured' => str_contains($label, 'configured'),
                    'not_configured' => str_contains($label, 'not configured'),
                    default => true,
                };
            });
        }
        if ($this->asset_attention === 'needs_attention') {
            $filteredAssets = $filteredAssets->filter(fn (array $a): bool => in_array($a['health'] ?? '', ['needs_attention', 'warning'], true));
        } elseif ($this->asset_attention === 'clear') {
            $filteredAssets = $filteredAssets->filter(fn (array $a): bool => ! in_array($a['health'] ?? '', ['needs_attention', 'warning'], true));
        }

        $websites = collect($assets)->where('type', 'website')->values()->all();
        if ($this->website_id === '' && count($websites) === 1) {
            $this->website_id = (string) ($websites[0]['id'] ?? '');
        }
        $selectedWebsite = collect($websites)->firstWhere('id', $this->website_id);

        $discoveryCandidates = collect($state['discovery_candidates'] ?? DemoCatalog::brandDiscoveryCandidates())
            ->values()
            ->all();

        $isAtlasBrand = ($brandRow['id'] ?? '') === DemoCatalog::BRAND_ID;

        $discoveryOverview = $isAtlasBrand
            ? BrandPublicDiscoveryFixtures::overview()
            : [
                'observed_facts' => 0,
                'awaiting_review' => 0,
                'conflicts' => 0,
                'accepted_recently' => 0,
                'public_identity' => [],
            ];

        if ($isAtlasBrand) {
            $discoveryOverview['awaiting_review'] = collect($discoveryCandidates)
                ->where('status', 'pending')
                ->count();
            $discoveryOverview['accepted_recently'] = collect($discoveryCandidates)
                ->whereIn('status', ['accepted', 'mapped'])
                ->count();
            $discoveryOverview['conflicts'] = collect(BrandPublicDiscoveryFixtures::conflicts())
                ->filter(function (array $conflict) use ($state): bool {
                    $resolution = $state['discovery_conflict_resolutions'][$conflict['id']] ?? null;

                    return $resolution === null;
                })
                ->count();
        }

        $discoveryConflicts = $isAtlasBrand
            ? collect(BrandPublicDiscoveryFixtures::conflicts())
                ->map(function (array $conflict) use ($state): array {
                    $resolution = $state['discovery_conflict_resolutions'][$conflict['id']] ?? null;
                    $conflict['resolution'] = is_array($resolution) ? ($resolution['decision'] ?? 'open') : 'open';
                    $conflict['resolved'] = is_array($resolution);

                    return $conflict;
                })
                ->values()
                ->all()
            : [];

        $discoveryFacts = $isAtlasBrand ? BrandPublicDiscoveryFixtures::observedFacts() : [];
        $discoverySources = $isAtlasBrand ? BrandPublicDiscoveryFixtures::sources() : [];
        $discoveryHistory = $isAtlasBrand
            ? array_reverse($state['discovery_history'] ?? BrandPublicDiscoveryFixtures::history())
            : [];
        $discoveryPublicIdentity = $isAtlasBrand ? BrandPublicDiscoveryFixtures::publicIdentity() : [];
        $existingOfferingsForMap = $isAtlasBrand ? BrandPublicDiscoveryFixtures::existingOfferingsForMap() : [];

        $reviewCandidate = collect($discoveryCandidates)->firstWhere('id', $this->reviewCandidateId);
        $reviewConflict = collect($discoveryConflicts)->firstWhere('id', $this->reviewConflictId);

        $analysis = DemoCatalog::brandAiAnalysis();
        $aiVisible = (bool) ($state['ai_brief_visible'] ?? false) || $isAtlasBrand;

        $sectorLabel = IndustryOptions::label($brandRow['sector'] ?? $brandRow['industry'] ?? null);
        $marketLabel = CountryOptions::label($brandRow['primary_country'] ?? null);
        $languageLabels = LanguageOptions::labels($brandRow['languages'] ?? []);

        $metaLine = collect([$sectorLabel, $marketLabel, $languageLabels !== [] ? implode(' + ', $languageLabels) : null])
            ->filter()
            ->implode(' · ');

        $historyChains = collect($allDecisionChains);
        if ($this->history_asset !== '') {
            $historyChains = $historyChains->filter(fn (array $c): bool => ($c['asset'] ?? '') === $this->history_asset);
        }
        if ($this->history_type !== '') {
            $historyChains = $historyChains->filter(function (array $c): bool {
                return match ($this->history_type) {
                    'finding' => ! empty($c['finding']),
                    'recommendation' => ! empty($c['recommendation']),
                    'task' => ! empty($c['task']),
                    'outcome' => ! empty($c['outcome']) && ($c['outcome'] ?? '') !== 'In progress',
                    default => true,
                };
            });
        }

        $historyAssetOptions = collect($allDecisionChains)
            ->pluck('asset')
            ->filter()
            ->unique()
            ->values()
            ->all();

        $assetTypeOptions = collect($assets)
            ->mapWithKeys(fn (array $a): array => [($a['type'] ?? '') => ($a['type_label'] ?? $a['type'] ?? '')])
            ->filter()
            ->all();

        $period = (string) ($this->period ?: ($state['period_preset'] ?? 'last_28'));
        $periodBounds = DemoPeriod::bounds(
            $period,
            $this->periodStart,
            $this->periodEnd,
        );
        $storyPeriodStart = ($this->periodStart && $this->periodEnd)
            ? $this->periodStart
            : $periodBounds['start']->toDateString();
        $storyPeriodEnd = ($this->periodStart && $this->periodEnd)
            ? $this->periodEnd
            : $periodBounds['end']->toDateString();

        $serviceScope = CommercialContextFixtures::effectiveScopeForBrand((string) ($brandRow['id'] ?? ''));
        $structuredGoals = CommercialContextFixtures::structuredGoalsForBrand((string) ($brandRow['id'] ?? ''));
        $brandOpportunities = OpportunityFixtures::sortByBusinessRelevance($this->brandOpportunities());

        $clientValueStory = null;
        if (ctype_digit((string) ($brandRow['id'] ?? ''))) {
            $clientValueStory = app(ClientValueStoryReadService::class)->forBrand(
                Brand::query()->findOrFail((int) $brandRow['id']),
                $storyPeriodStart,
                $storyPeriodEnd,
            );
        }

        if (($brandRow['id'] ?? '') === DemoCatalog::BRAND_ID) {
            // Isolated Demo catalog brand: outcomes remain empty (Prompt 57); story fixtures are Demo-only.
            $businessOutcomes = [
                'available' => false,
                'period_label' => $period,
                'qualified_leads' => '—',
                'qualified_leads_label' => __('operator.outcomes.qualified_leads'),
                'consultations' => '—',
                'consultations_label' => __('operator.outcomes.consultations'),
                'patients' => '—',
                'patients_label' => __('operator.outcomes.patients'),
                'revenue' => null,
                'revenue_label' => __('operator.outcomes.revenue'),
                'revenue_display' => __('operator.outcomes.not_available'),
                'platform_leads' => '—',
                'platform_leads_label' => __('operator.outcomes.platform_results'),
                'qualified_rate' => '—',
                'empty_message' => 'No reported Business Outcome data for this period.',
                'demo' => false,
                'provenance' => 'business_outcome',
                'note' => __('operator.outcomes.brand_aggregate_note'),
            ];
        } elseif ($clientValueStory !== null) {
            $surface = app(BusinessOutcomeReadService::class)->forValueSurface(
                Brand::query()->findOrFail((int) $brandRow['id']),
                $storyPeriodStart,
                $storyPeriodEnd,
            );
            $businessOutcomes = array_merge($surface, [
                'qualified_leads_label' => __('operator.outcomes.qualified_leads'),
                'consultations_label' => __('operator.outcomes.consultations'),
                'patients_label' => __('operator.outcomes.patients'),
                'revenue_label' => __('operator.outcomes.revenue'),
                'platform_leads_label' => __('operator.outcomes.platform_results'),
                'platform_leads' => $surface['platform_leads'] ?? '—',
                'qualified_rate' => '—',
                'note' => __('operator.outcomes.brand_aggregate_note'),
                'period_label' => ($surface['period_start'] ?? '').' → '.($surface['period_end'] ?? ''),
                'qualified_leads' => $surface['qualified_leads'] ?? '—',
                'consultations' => $surface['consultations'] ?? '—',
                'patients' => $surface['patients'] ?? '—',
            ]);
        } else {
            $businessOutcomes = null;
        }

        $operationalOutcomes = ($brandRow['id'] ?? '') === DemoCatalog::BRAND_ID
            ? BusinessOutcomeFixtures::operationalOutcomes()
            : [];

        if (($brandRow['id'] ?? '') === DemoCatalog::BRAND_ID) {
            $valueSummary = ClientValueFixtures::valueSummary($period);
            $valueStory = ClientValueFixtures::valueStory($period, $this->reportLanguage);
        } elseif ($clientValueStory !== null) {
            $valueSummary = $clientValueStory->toSummaryArray();
            $valueStory = $clientValueStory->toPresentationArray();
        } else {
            $valueSummary = null;
            $valueStory = null;
        }
        $valueDecisions = ($brandRow['id'] ?? '') === DemoCatalog::BRAND_ID
            ? ClientValueFixtures::meaningfulDecisions($this->reportLanguage)
            : [];
        $reportPreview = ($brandRow['id'] ?? '') === DemoCatalog::BRAND_ID
            ? ClientValueFixtures::reportPreview([
                'period' => $period,
                'language' => $this->reportLanguage,
                'tone' => $this->reportTone,
                'operator_note' => $this->reportOperatorNote,
                'sections' => $this->reportSections !== []
                    ? $this->reportSections
                    : array_fill_keys(ClientValueFixtures::reportSectionKeys(), true),
            ])
            : null;

        $brandName = (string) ($brandRow['name'] ?? '');
        $brandId = (string) ($brandRow['id'] ?? $this->brand);
        $brandWorkItems = collect(AgencyExecutionFixtures::workItems())
            ->filter(function (array $row) use ($brandName, $brandId): bool {
                if (($row['type'] ?? '') === 'task' && ctype_digit($brandId)) {
                    return (string) ($row['brand_id'] ?? '') === $brandId
                        || (($row['brand'] ?? '') === $brandName && ($row['brand_id'] ?? null) === null);
                }

                return ($row['brand'] ?? '') === $brandName;
            })
            ->values()
            ->all();
        $brandRequests = [];
        if (ctype_digit((string) ($brandRow['id'] ?? $this->brand))) {
            $brandRequests = app(ClientRequestReadService::class)
                ->forBrandPresentation((int) ($brandRow['id'] ?? $this->brand));
        }
        $brandReviews = [];
        if (ctype_digit((string) ($brandRow['id'] ?? $this->brand))) {
            $brandReviews = app(RecurringReviewReadService::class)
                ->forBrandPresentation((int) ($brandRow['id'] ?? $this->brand));
        }
        $brandApprovals = [];
        if (ctype_digit((string) ($brandRow['id'] ?? $this->brand))) {
            $brandApprovals = app(ApprovalReadService::class)
                ->forBrandPresentation((int) ($brandRow['id'] ?? $this->brand));
        }

        return view('livewire.demo.portfolio.brand-show', [
            'brandRow' => $brandRow,
            'customer' => $customer,
            'responsibleUsers' => $responsibleUsers,
            'metaLine' => $metaLine,
            'assets' => $assets,
            'filteredAssets' => $filteredAssets->values()->all(),
            'assetTypeOptions' => $assetTypeOptions,
            'findings' => $findings,
            'recommendations' => $recommendations,
            'tasks' => $tasks,
            'attention' => $attention,
            'priorities' => $priorities,
            'businessContext' => $businessContext,
            'crossChannel' => $crossChannel,
            'decisionChains' => $historyChains->values()->all(),
            'historyAssetOptions' => $historyAssetOptions,
            'recentActivity' => $recentActivity,
            'glance' => [
                'assets' => count($assets),
                'connected' => $connectedAssets,
                'open_findings' => $openFindings,
                'open_recommendations' => $openRecommendations,
                'open_tasks' => $openTasks,
            ],
            'opsSummary' => [
                'open_findings' => $openFindings,
                'open_recommendations' => $openRecommendations,
                'open_tasks' => $openTasks,
                'blocked_overdue' => $blockedOrOverdue,
                'awaiting_follow_up' => $awaitingFollowUp,
                'regressions' => $regressions,
            ],
            'websites' => $websites,
            'selectedWebsite' => $selectedWebsite,
            'discoveryCandidates' => $discoveryCandidates,
            'discoveryOverview' => $discoveryOverview,
            'discoveryFacts' => $discoveryFacts,
            'discoveryConflicts' => $discoveryConflicts,
            'discoverySources' => $discoverySources,
            'discoveryHistory' => $discoveryHistory,
            'discoveryPublicIdentity' => $discoveryPublicIdentity,
            'existingOfferingsForMap' => $existingOfferingsForMap,
            'reviewCandidate' => $reviewCandidate,
            'reviewConflict' => $reviewConflict,
            'research' => $state['public_research'] ?? [],
            'aiBrief' => $aiVisible ? $analysis : null,
            'serviceScope' => $serviceScope,
            'structuredGoals' => $structuredGoals,
            'brandOpportunities' => $brandOpportunities,
            'businessOutcomes' => $businessOutcomes,
            'operationalOutcomes' => $operationalOutcomes,
            'outcomePeriod' => $period,
            'valueSummary' => $valueSummary,
            'valueStory' => $valueStory,
            'valueDecisions' => $valueDecisions,
            'reportPreview' => $reportPreview,
            'brandWorkItems' => $brandWorkItems,
            'brandRequests' => $brandRequests,
            'brandReviews' => $brandReviews,
            'brandApprovals' => $brandApprovals,
            'flash' => DemoState::pullFlash(),
        ]);
    }
}
