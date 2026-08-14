<?php

namespace App\Support\Demo;

use App\Services\Work\WorkReadService;
use Illuminate\Support\Collection;

/**
 * Deterministic Atlas agency execution fixtures — playbooks, reviews, work queue helpers.
 *
 * Client Requests in Work are production DB-backed (Prompt 42 via ClientRequestReadService).
 * Playbooks / recurring reviews / approvals / QA remain Demo session fixtures.
 */
final class AgencyExecutionFixtures
{
    public const string CURRENT_OPERATOR_ID = 'u-ayse';

    /**
     * @return list<array<string, mixed>>
     */
    public static function playbooks(): array
    {
        return [
            [
                'id' => 'pb-weekly-gads',
                'name' => 'Weekly Google Ads Review',
                'purpose' => 'Monitor paid search efficiency, waste, and conversion signal health for Atlas Dental.',
                'service_code' => 'google_ads',
                'service_label' => 'Google Ads',
                'asset_types' => ['google_ads'],
                'cadence' => 'weekly',
                'default_owner_id' => 'u-mert',
                'default_owner_name' => 'Yakup',
                'checklist' => [
                    'Confirm primary conversion signal is firing',
                    'Review search-term waste and negatives',
                    'Check campaign budget pacing',
                    'Inspect landing page alignment for top campaigns',
                    'Log findings or opportunities if thresholds breached',
                ],
                'when_to_use' => [
                    'Google Ads Management service is active.',
                    'Account has spend and conversion data for the prior week.',
                ],
                'when_not_to_use' => [
                    'Service is paused.',
                    'Account has no usable data.',
                    'Campaigns have not started yet.',
                ],
                'methodology' => [
                    'Inspect meaningful-spend search terms.',
                    'Separate intent mismatch from low-volume noise.',
                    'Check Goal / Offering relevance.',
                    'Review landing-page alignment.',
                    'Produce Finding, Opportunity, or No Issue.',
                ],
                'instructions' => 'Run every Monday before noon. Compare against prior week. Escalate measurement gaps immediately.',
                'qa_guidance' => [
                    'Conversion event still mapping to the intended Business Action',
                    'No unintended budget or bid changes left unpublished',
                    'Findings logged with Evidence links where applicable',
                ],
                'related_ai_skill' => [
                    'id' => 'skill-search-query',
                    'name' => 'Search Query Analysis',
                    'note' => null,
                ],
                'references' => [
                    ['label' => 'Atlas Google Ads workspace', 'route' => 'demo.google-ads.overview'],
                    ['label' => 'Lead measurement finding', 'route' => 'demo.findings'],
                ],
                'possible_outputs' => ['no_issue', 'finding', 'opportunity', 'task'],
                'active' => true,
            ],
            [
                'id' => 'pb-monthly-seo',
                'name' => 'Monthly SEO Coverage Review',
                'purpose' => 'Assess organic visibility, content coverage, and indexing health.',
                'service_code' => 'seo',
                'service_label' => 'SEO / Organic Growth',
                'asset_types' => ['gsc', 'website'],
                'cadence' => 'monthly',
                'default_owner_id' => 'u-selin',
                'default_owner_name' => 'Selin Kaya',
                'checklist' => [
                    'Review priority query coverage vs goals',
                    'Check indexing and crawl anomalies',
                    'Compare content depth for priority offerings',
                    'Identify cross-channel gaps with paid demand',
                    'Document opportunities or tasks',
                ],
                'when_to_use' => [
                    'SEO / Organic Growth service is active.',
                    'Search Console asset exists for the Brand.',
                ],
                'when_not_to_use' => [
                    'SEO service paused.',
                    'No Search Console property connected.',
                ],
                'methodology' => [
                    'Compare priority offering queries vs content ownership.',
                    'Inspect indexing health on money pages.',
                    'Cross-check paid demand without organic coverage.',
                    'Log Opportunity or Task only when action is warranted.',
                ],
                'instructions' => 'Run first business day of the month. Cross-reference GSC and Website health tabs.',
                'qa_guidance' => [
                    'Opportunities cite Goal and Service Scope',
                    'No fake Opportunity score assigned',
                ],
                'related_ai_skill' => null,
                'references' => [
                    ['label' => 'Search Console workspace', 'route' => 'demo.search-console'],
                    ['label' => 'Website workspace', 'route' => 'demo.website'],
                ],
                'possible_outputs' => ['no_issue', 'finding', 'opportunity', 'task'],
                'active' => true,
            ],
            [
                'id' => 'pb-meta-creative',
                'name' => 'Meta Creative Review',
                'purpose' => 'Review creative fatigue, frequency, and CPL trends on Meta campaigns.',
                'service_code' => 'meta_ads',
                'service_label' => 'Meta Ads',
                'asset_types' => ['meta_ads'],
                'cadence' => 'weekly',
                'default_owner_id' => 'u-ayse',
                'default_owner_name' => 'Ayşe Demir',
                'checklist' => [
                    'Inspect top-spend ad sets for CPL drift',
                    'Check creative frequency and CTR decay',
                    'Review audience overlap signals',
                    'Validate landing destinations',
                    'Queue creative refresh task if needed',
                ],
                'when_to_use' => [
                    'Meta Ads Management service is active.',
                    'Campaigns have delivered in the review window.',
                ],
                'when_not_to_use' => [
                    'Meta service paused.',
                    'No creative spend in the period.',
                ],
                'methodology' => [
                    'Rank ad sets by spend and CPL drift.',
                    'Inspect frequency and CTR decay on top creatives.',
                    'Confirm landing destinations still match offers.',
                    'Queue refresh Task only when thresholds breach.',
                ],
                'instructions' => 'Run mid-week. Pair with Meta workspace insights tab.',
                'qa_guidance' => [
                    'Creative replacements reviewed on mobile',
                    'Destination URLs verified',
                ],
                'related_ai_skill' => null,
                'references' => [
                    ['label' => 'Meta Ads workspace', 'route' => 'demo.meta.overview'],
                ],
                'possible_outputs' => ['no_issue', 'finding', 'opportunity', 'task'],
                'active' => true,
            ],
            [
                'id' => 'pb-website-health',
                'name' => 'Website Health Review',
                'purpose' => 'Monitor performance, uptime signals, and critical page health.',
                'service_code' => 'website_maintenance',
                'service_label' => 'Website Maintenance',
                'asset_types' => ['website'],
                'cadence' => 'monthly',
                'default_owner_id' => 'u-can',
                'default_owner_name' => 'Can Öztürk',
                'checklist' => [
                    'Review Core Web Vitals on priority URLs',
                    'Check SSL and redirect integrity',
                    'Scan for broken links on key funnels',
                    'Confirm connector health',
                    'Log maintenance tasks if regressions found',
                ],
                'when_to_use' => [
                    'Website Management / Maintenance is in Service Scope.',
                    'Website asset exists.',
                ],
                'when_not_to_use' => [
                    'Website service not in scope.',
                    'Site is offline for planned maintenance already tracked.',
                ],
                'methodology' => [
                    'Check Core Web Vitals on money pages.',
                    'Verify redirects and SSL.',
                    'Confirm critical forms still convert.',
                    'Log Task only for actionable regressions.',
                ],
                'instructions' => 'Run monthly after analytics close. No provider writes from MoxDOP.',
                'qa_guidance' => [
                    'Correct target URL after content changes',
                    'Mobile layout reviewed',
                    'Conversion event verified',
                    'No unintended content change',
                ],
                'related_ai_skill' => null,
                'references' => [
                    ['label' => 'Website workspace', 'route' => 'demo.website'],
                ],
                'possible_outputs' => ['no_issue', 'finding', 'opportunity', 'task'],
                'active' => true,
            ],
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function playbook(string $id): ?array
    {
        return collect(self::playbooks())->firstWhere('id', $id);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function clientRequests(): array
    {
        $brand = DemoCatalog::brand();
        $customer = DemoCatalog::customer();

        return [
            [
                'id' => 'req-doctor-title',
                'title' => "Update doctor's title on homepage",
                'customer_id' => DemoCatalog::CUSTOMER_ID,
                'customer' => $customer['name'],
                'brand_id' => DemoCatalog::BRAND_ID,
                'brand' => $brand['name'],
                'asset' => 'Website',
                'asset_type' => 'website',
                'service_code' => 'website_maintenance',
                'service_label' => 'Website Maintenance',
                'source' => 'meeting',
                'source_label' => 'Meeting',
                'status' => 'planned',
                'waiting_on_client' => false,
                'in_scope' => true,
                'owner_id' => 'u-mert',
                'owner' => 'Yakup',
                'due' => 'Today',
                'due_key' => 'today',
                'priority' => 'medium',
                'effort' => '30m',
                'goal_title' => null,
                'offering' => null,
                'linked_task_id' => null,
                'description' => 'Clinic requested title update for lead dentist on homepage hero.',
            ],
            [
                'id' => 'req-instagram-daily',
                'title' => 'Daily Instagram posting',
                'customer_id' => DemoCatalog::CUSTOMER_ID,
                'customer' => $customer['name'],
                'brand_id' => DemoCatalog::BRAND_ID,
                'brand' => $brand['name'],
                'asset' => 'Instagram',
                'asset_type' => 'instagram',
                'service_code' => null,
                'service_label' => null,
                'source' => 'email',
                'source_label' => 'Email',
                'status' => 'new',
                'waiting_on_client' => false,
                'in_scope' => false,
                'owner_id' => null,
                'owner' => 'Unassigned',
                'due' => '—',
                'due_key' => 'none',
                'priority' => 'low',
                'effort' => null,
                'goal_title' => null,
                'offering' => null,
                'linked_task_id' => null,
                'description' => 'Client asked for daily Instagram posts — outside current Service Scope.',
            ],
            [
                'id' => 'req-landing-copy',
                'title' => 'Landing page copy update',
                'customer_id' => DemoCatalog::CUSTOMER_ID,
                'customer' => $customer['name'],
                'brand_id' => DemoCatalog::BRAND_ID,
                'brand' => $brand['name'],
                'asset' => 'Website',
                'asset_type' => 'website',
                'service_code' => 'website_maintenance',
                'service_label' => 'Website Maintenance',
                'source' => 'whatsapp',
                'source_label' => 'WhatsApp',
                'status' => 'waiting_on_client',
                'waiting_on_client' => true,
                'in_scope' => true,
                'owner_id' => 'u-can',
                'owner' => 'Can Öztürk',
                'due' => 'Friday',
                'due_key' => 'soon',
                'priority' => 'medium',
                'effort' => '1h',
                'goal_title' => 'Increase qualified implant consultations',
                'offering' => 'Dental Implants',
                'linked_task_id' => null,
                'approval_id' => 'appr-landing-copy',
                'description' => 'Draft copy ready — awaiting client approval before publish.',
            ],
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function clientRequest(string $id): ?array
    {
        return collect(self::clientRequests())->firstWhere('id', $id);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function recurringReviews(): array
    {
        $brand = DemoCatalog::brand();

        return [
            [
                'id' => 'rr-gads-aug13',
                'playbook_id' => 'pb-weekly-gads',
                'playbook_name' => 'Weekly Google Ads Review',
                'brand_id' => DemoCatalog::BRAND_ID,
                'brand' => $brand['name'],
                'customer_id' => DemoCatalog::CUSTOMER_ID,
                'customer' => DemoCatalog::customer()['name'],
                'service_code' => 'google_ads',
                'service_label' => 'Google Ads',
                'asset_type' => 'google_ads',
                'owner_id' => 'u-mert',
                'owner' => 'Yakup',
                'due' => 'Today',
                'due_key' => 'today',
                'status' => 'due',
                'cadence' => 'weekly',
            ],
            [
                'id' => 'rr-seo-aug14',
                'playbook_id' => 'pb-monthly-seo',
                'playbook_name' => 'Monthly SEO Coverage Review',
                'brand_id' => DemoCatalog::BRAND_ID,
                'brand' => $brand['name'],
                'customer_id' => DemoCatalog::CUSTOMER_ID,
                'customer' => DemoCatalog::customer()['name'],
                'service_code' => 'seo',
                'service_label' => 'SEO / Organic Growth',
                'asset_type' => 'gsc',
                'owner_id' => 'u-selin',
                'owner' => 'Selin Kaya',
                'due' => 'Tomorrow',
                'due_key' => 'soon',
                'status' => 'upcoming',
                'cadence' => 'monthly',
            ],
            [
                'id' => 'rr-gads-overdue',
                'playbook_id' => 'pb-meta-creative',
                'playbook_name' => 'Meta Creative Review',
                'brand_id' => DemoCatalog::BRAND_ID,
                'brand' => $brand['name'],
                'customer_id' => DemoCatalog::CUSTOMER_ID,
                'customer' => DemoCatalog::customer()['name'],
                'service_code' => 'meta_ads',
                'service_label' => 'Meta Ads',
                'asset_type' => 'meta_ads',
                'owner_id' => 'u-ayse',
                'owner' => 'Ayşe Demir',
                'due' => 'Last week',
                'due_key' => 'overdue',
                'status' => 'overdue',
                'cadence' => 'weekly',
            ],
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function approvals(): array
    {
        return [
            [
                'id' => 'appr-landing-copy',
                'title' => 'Client approval — landing page copy',
                'type' => 'client',
                'status' => 'waiting',
                'client_request_id' => 'req-landing-copy',
                'task_id' => null,
                'brand' => DemoCatalog::brand()['name'],
                'customer' => DemoCatalog::customer()['name'],
                'owner_id' => 'u-can',
                'owner' => 'Can Öztürk',
                'waiting_on_client' => true,
            ],
            [
                'id' => 'appr-qa-creative',
                'title' => 'QA review — Meta creative replacement',
                'type' => 'qa',
                'status' => 'waiting',
                'client_request_id' => null,
                'task_id' => 't-replace-creative',
                'brand' => DemoCatalog::brand()['name'],
                'customer' => DemoCatalog::customer()['name'],
                'owner_id' => 'u-ayse',
                'owner' => 'Ayşe Demir',
                'waiting_on_client' => false,
            ],
        ];
    }

    /**
     * Work aggregate over canonical Tasks (+ residual Demo reviews/approvals until P44–46).
     * No Demo Task fallback. No works table.
     *
     * @return list<array<string, mixed>>
     */
    public static function workItems(): array
    {
        return app(WorkReadService::class)->workItems();
    }

    /**
     * @param  array<string, mixed>  $review
     * @return array<string, mixed>
     */
    public static function mapRecurringReviewToWorkItemPublic(array $review): array
    {
        return self::mapRecurringReviewToWorkItem($review);
    }

    /**
     * @param  array<string, mixed>  $approval
     * @return array<string, mixed>
     */
    public static function mapApprovalToWorkItemPublic(array $approval): array
    {
        return self::mapApprovalToWorkItem($approval);
    }

    /**
     * @param  array<string, mixed>  $task
     * @param  array<string, mixed>  $state
     * @return array<string, mixed>
     */
    private static function mapTaskToWorkItem(array $task, array $state): array
    {
        $id = (string) ($task['id'] ?? '');
        $qaStates = is_array($state['qa_states'] ?? null) ? $state['qa_states'] : [];
        $qaStatus = is_string($qaStates[$id] ?? null) ? $qaStates[$id] : null;
        $qaRequired = $qaStatus !== null || in_array($id, ['t-replace-creative'], true);
        if ($qaRequired && $qaStatus === null) {
            $qaStatus = 'ready';
        }

        $due = (string) ($task['due'] ?? '—');
        $status = (string) ($task['status'] ?? 'open');

        return [
            'id' => $id,
            'type' => 'task',
            'title' => (string) ($task['title'] ?? ''),
            'customer' => (string) ($task['customer'] ?? DemoCatalog::customer()['name']),
            'brand' => (string) ($task['brand'] ?? DemoCatalog::brand()['name']),
            'asset' => $task['asset'] ?? null,
            'asset_type' => $task['asset_type'] ?? null,
            'owner' => (string) ($task['owner'] ?? 'Unassigned'),
            'owner_id' => $task['assignee_id'] ?? null,
            'due' => $due,
            'due_key' => self::dueKey($due, $status),
            'status' => $status,
            'waiting_on_client' => $status === 'blocked' && str_contains(mb_strtolower($due), 'waiting'),
            'qa_required' => $qaRequired,
            'qa_status' => $qaStatus,
            'priority' => $task['priority'] ?? 'medium',
            'effort' => self::effortForTask($task),
            'service_label' => $task['service_label'] ?? null,
            'goal_title' => $task['goal_title'] ?? null,
            'offering' => $task['offering'] ?? null,
            'source' => strtolower((string) ($task['origin'] ?? 'task')),
            'source_label' => (string) ($task['origin'] ?? 'Task'),
            'in_scope' => true,
            'route' => 'demo.task',
            'route_params' => ['taskId' => $id],
        ];
    }

    /**
     * @param  array<string, mixed>  $request
     * @return array<string, mixed>
     */
    private static function mapClientRequestToWorkItem(array $request): array
    {
        $status = (string) ($request['status'] ?? 'new');
        $due = (string) ($request['due'] ?? '—');

        return [
            'id' => (string) ($request['id'] ?? ''),
            'type' => 'client_request',
            'title' => (string) ($request['title'] ?? ''),
            'customer' => (string) ($request['customer'] ?? ''),
            'brand' => (string) ($request['brand'] ?? ''),
            'asset' => $request['asset'] ?? null,
            'asset_type' => $request['asset_type'] ?? null,
            'owner' => (string) ($request['owner'] ?? 'Unassigned'),
            'owner_id' => $request['owner_id'] ?? null,
            'due' => $due,
            'due_key' => $request['due_key'] ?? self::dueKey($due, $status),
            'status' => $status,
            'waiting_on_client' => (bool) ($request['waiting_on_client'] ?? false),
            'qa_required' => false,
            'qa_status' => null,
            'priority' => $request['priority'] ?? 'medium',
            'effort' => $request['effort'] ?? null,
            'service_label' => $request['service_label'] ?? null,
            'goal_title' => $request['goal_title'] ?? null,
            'offering' => $request['offering'] ?? null,
            'source' => (string) ($request['source'] ?? 'client'),
            'source_label' => (string) ($request['source_label'] ?? 'Client'),
            'in_scope' => array_key_exists('in_scope', $request) ? $request['in_scope'] : true,
            'linked_task_id' => $request['linked_task_id'] ?? null,
            'route' => 'demo.work.show',
            'route_params' => ['workId' => $request['id'], 'type' => 'client_request'],
        ];
    }

    /**
     * @param  array<string, mixed>  $review
     * @return array<string, mixed>
     */
    private static function mapRecurringReviewToWorkItem(array $review): array
    {
        $status = (string) ($review['status'] ?? 'due');
        $due = (string) ($review['due'] ?? '—');

        return [
            'id' => (string) ($review['id'] ?? ''),
            'type' => 'recurring_review',
            'title' => (string) ($review['playbook_name'] ?? 'Recurring review'),
            'customer' => (string) ($review['customer'] ?? ''),
            'brand' => (string) ($review['brand'] ?? ''),
            'asset' => null,
            'asset_type' => $review['asset_type'] ?? null,
            'owner' => (string) ($review['owner'] ?? ''),
            'owner_id' => $review['owner_id'] ?? null,
            'due' => $due,
            'due_key' => $review['due_key'] ?? self::dueKey($due, $status),
            'status' => $status,
            'waiting_on_client' => false,
            'qa_required' => false,
            'qa_status' => null,
            'priority' => in_array($status, ['due', 'overdue'], true) ? 'high' : 'medium',
            'effort' => '1h',
            'service_label' => $review['service_label'] ?? null,
            'goal_title' => null,
            'offering' => null,
            'source' => 'playbook',
            'source_label' => 'Playbook',
            'in_scope' => true,
            'playbook_id' => $review['playbook_id'] ?? null,
            'route' => 'demo.work.show',
            'route_params' => ['workId' => $review['id'], 'type' => 'recurring_review'],
        ];
    }

    /**
     * @param  array<string, mixed>  $approval
     * @return array<string, mixed>
     */
    private static function mapApprovalToWorkItem(array $approval): array
    {
        return [
            'id' => (string) ($approval['id'] ?? ''),
            'type' => 'approval',
            'title' => (string) ($approval['title'] ?? ''),
            'customer' => (string) ($approval['customer'] ?? ''),
            'brand' => (string) ($approval['brand'] ?? ''),
            'asset' => null,
            'asset_type' => null,
            'owner' => (string) ($approval['owner'] ?? ''),
            'owner_id' => $approval['owner_id'] ?? null,
            'due' => '—',
            'due_key' => 'none',
            'status' => (string) ($approval['status'] ?? 'waiting'),
            'waiting_on_client' => (bool) ($approval['waiting_on_client'] ?? false),
            'qa_required' => ($approval['type'] ?? '') === 'qa',
            'qa_status' => ($approval['type'] ?? '') === 'qa' ? 'ready' : null,
            'priority' => 'medium',
            'effort' => '15m',
            'service_label' => null,
            'goal_title' => null,
            'offering' => null,
            'source' => 'approval',
            'source_label' => 'Approval',
            'in_scope' => true,
            'route' => 'demo.work.show',
            'route_params' => ['workId' => $approval['id'], 'type' => 'approval'],
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function approvalsWithState(): array
    {
        $states = DemoState::all()['approval_states'] ?? [];
        if (! is_array($states)) {
            $states = [];
        }

        return array_map(function (array $approval) use ($states): array {
            $id = (string) ($approval['id'] ?? '');
            if ($id !== '' && isset($states[$id]) && is_array($states[$id])) {
                $approval = array_merge($approval, $states[$id]);
            }

            return $approval;
        }, self::approvals());
    }

    /**
     * @return array<string, mixed>
     */
    public static function teamCapacity(): array
    {
        $items = collect(self::workItems())->filter(function (array $item): bool {
            return ! in_array($item['status'] ?? '', ['completed', 'done', 'declined', 'skipped', 'dismissed'], true);
        });

        $active = $items->count();
        $dueToday = $items->where('due_key', 'today')->count();
        $overdue = $items->where('due_key', 'overdue')->count();
        $plannedHours = $items->sum(fn (array $row): float => self::effortToHours($row['effort'] ?? null));

        $label = match (true) {
            $overdue > 3 || $active > 10 => 'Overloaded',
            $overdue >= 2 || $active >= 7 => 'Heavy',
            $active >= 4 || $overdue >= 1 => 'Balanced',
            default => 'Light',
        };

        return [
            'active_count' => $active,
            'due_today' => $dueToday,
            'overdue' => $overdue,
            'planned_hours' => round($plannedHours, 1),
            'label' => $label,
            'thresholds' => [
                'light' => 'active ≤ 3 and overdue = 0',
                'balanced' => 'active 4–6 or overdue = 1',
                'heavy' => 'active 7–10 or overdue 2–3',
                'overloaded' => 'active > 10 or overdue > 3',
            ],
            'members' => self::memberCapacity($items),
        ];
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $items
     * @return list<array<string, mixed>>
     */
    private static function memberCapacity(Collection $items): array
    {
        return collect(DemoCatalog::teamMembers())->map(function (array $member) use ($items): array {
            $mine = $items->filter(fn (array $row): bool => ($row['owner_id'] ?? '') === $member['id']);
            $overdue = $mine->where('due_key', 'overdue')->count();

            return [
                'id' => $member['id'],
                'name' => $member['name'],
                'active' => $mine->count(),
                'due_today' => $mine->where('due_key', 'today')->count(),
                'overdue' => $overdue,
                'label' => match (true) {
                    $overdue > 1 || $mine->count() > 5 => 'Heavy',
                    $mine->count() >= 3 => 'Balanced',
                    default => 'Light',
                },
            ];
        })->values()->all();
    }

    /**
     * @return array<string, mixed>
     */
    public static function dashboardExecution(string $mode = 'my_work'): array
    {
        $mode = in_array($mode, ['my_work', 'agency'], true) ? $mode : 'my_work';
        $items = collect(self::workItems());
        $openItems = $items->reject(fn (array $row): bool => in_array($row['status'] ?? '', ['completed', 'done', 'declined', 'skipped'], true));
        $recs = collect(DemoState::all()['recommendations'] ?? DemoCatalog::recommendationsSeed());
        $awaitingDecision = $recs->whereIn('status', ['pending', 'awaiting_decision'])->count();
        $waitingOnClient = $openItems->where('waiting_on_client', true)->count();

        $myItems = $openItems->filter(fn (array $row): bool => self::isMine($row));

        return [
            'mode' => $mode,
            'greeting' => GlobalOperatingFixtures::dashboard($mode)['greeting'],
            'date_label' => now()->timezone(config('app.timezone'))->format('l, F j'),
            'subtitle' => __('operator.dashboard_exec.subtitle'),
            'today' => [
                ['label' => __('operator.dashboard_exec.due_today'), 'value' => $openItems->where('due_key', 'today')->count(), 'route' => 'demo.tasks', 'route_params' => ['view' => 'due_today'], 'tone' => 'warning'],
                ['label' => __('operator.dashboard_exec.overdue'), 'value' => $openItems->where('due_key', 'overdue')->count(), 'route' => 'demo.tasks', 'route_params' => ['view' => 'overdue'], 'tone' => 'error'],
                ['label' => __('operator.dashboard_exec.awaiting_decision'), 'value' => $awaitingDecision, 'route' => 'demo.recommendations', 'route_params' => [], 'tone' => 'info'],
                ['label' => __('operator.dashboard_exec.waiting_on_client'), 'value' => $waitingOnClient, 'route' => 'demo.tasks', 'route_params' => ['view' => 'waiting_on_client'], 'tone' => 'info'],
            ],
            'needs_attention' => self::attentionFromWork($openItems, $mode),
            'my_work' => $myItems->sortBy(fn (array $row): int => match ($row['due_key'] ?? '') {
                'overdue' => 0,
                'today' => 1,
                'soon' => 2,
                default => 3,
            })->take(8)->values()->all(),
            'team_capacity' => self::teamCapacity(),
            'recurring_reviews_due' => $openItems->where('type', 'recurring_review')
                ->whereIn('status', ['due', 'overdue', 'upcoming'])
                ->take(5)->values()->all(),
            'portfolio_focus' => self::portfolioFocus(),
            'system_exceptions' => collect(GlobalOperatingFixtures::integrationAttention())
                ->whereIn('state', ['needs_attention', 'configuration_incomplete', 'failed'])
                ->values()->all(),
            'recent_outcomes' => GlobalOperatingFixtures::dashboard($mode)['recent_outcomes'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function captureDefaults(): array
    {
        return [
            'client_request' => [
                'title' => '',
                'customer_id' => DemoCatalog::CUSTOMER_ID,
                'brand_id' => DemoCatalog::BRAND_ID,
                'asset_type' => 'website',
                'source' => 'meeting',
                'priority' => 'medium',
            ],
            'task' => [
                'title' => '',
                'customer_id' => DemoCatalog::CUSTOMER_ID,
                'brand_id' => DemoCatalog::BRAND_ID,
                'priority' => 'medium',
                'due' => 'Next week',
            ],
            'opportunity_hypothesis' => [
                'title' => '',
                'brand_id' => DemoCatalog::BRAND_ID,
                'service_code' => 'seo',
            ],
            'note' => [
                'title' => '',
                'scope' => 'Operations',
            ],
        ];
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $items
     * @return list<array<string, mixed>>
     */
    private static function attentionFromWork(Collection $items, string $mode): array
    {
        $base = GlobalOperatingFixtures::attentionItems($mode);
        $workLimit = $mode === 'agency' ? 3 : 5;
        $workAttention = $items
            ->filter(fn (array $row): bool => in_array($row['due_key'] ?? '', ['overdue', 'today'], true)
                || ($row['waiting_on_client'] ?? false)
                || ($row['qa_required'] ?? false))
            ->take($workLimit)
            ->map(fn (array $row): array => [
                'severity' => match ($row['due_key'] ?? '') {
                    'overdue' => 'high',
                    'today' => 'medium',
                    default => 'medium',
                },
                'title' => $row['title'],
                'body' => ucfirst(str_replace('_', ' ', (string) ($row['type'] ?? 'work'))).' · '.($row['brand'] ?? ''),
                'evidence' => ($row['owner'] ?? '').' · due '.($row['due'] ?? '—'),
                'why' => ($row['waiting_on_client'] ?? false) ? 'Waiting on client blocks progress.' : 'Open execution item needs action.',
                'source' => ucfirst(str_replace('_', ' ', (string) ($row['type'] ?? 'work'))),
                'route' => $row['route'] ?? 'demo.tasks',
                'route_params' => $row['route_params'] ?? [],
                'action_label' => __('operator.actions.open'),
            ])
            ->values()
            ->all();

        return array_slice(array_merge($workAttention, $base), 0, 7);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function portfolioFocus(): array
    {
        return [
            [
                'brand' => DemoCatalog::brand()['name'],
                'reasons' => [
                    'Lead measurement finding open on Google Ads',
                    '1 overdue recurring review (Meta Creative)',
                    'Client request due today (homepage title)',
                ],
                'route' => 'demo.brand',
                'route_params' => ['brand' => DemoCatalog::BRAND_ID],
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $row
     */
    public static function isMine(array $row): bool
    {
        $ownerId = $row['owner_id'] ?? null;
        if ($ownerId === self::CURRENT_OPERATOR_ID) {
            return true;
        }

        $owner = (string) ($row['owner'] ?? '');

        return in_array($owner, ['Ayşe Demir', 'Ayşe Yılmaz'], true);
    }

    public static function dueKey(string $due, string $status = 'open'): string
    {
        if (in_array($status, ['completed', 'done', 'declined', 'skipped'], true)) {
            return 'none';
        }

        $normalized = mb_strtolower($due);

        return match (true) {
            in_array($due, ['Last week', 'Yesterday', 'Overdue'], true) => 'overdue',
            str_contains($normalized, 'overdue') => 'overdue',
            $due === 'Today' => 'today',
            in_array($due, ['Tomorrow', 'Friday'], true) => 'soon',
            default => 'none',
        };
    }

    /**
     * @param  array<string, mixed>  $task
     */
    private static function effortForTask(array $task): ?string
    {
        return match ((string) ($task['priority'] ?? '')) {
            'critical' => '2h',
            'high' => '1h',
            'medium' => '30m',
            default => '15m',
        };
    }

    private static function effortToHours(?string $effort): float
    {
        return match ($effort) {
            '15m' => 0.25,
            '30m' => 0.5,
            '1h' => 1.0,
            '2h' => 2.0,
            'half_day' => 4.0,
            '1d' => 8.0,
            default => 0.5,
        };
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function recentReviewsForPlaybook(string $playbookId): array
    {
        return collect(self::recurringReviews())
            ->filter(fn (array $row): bool => ($row['playbook_id'] ?? '') === $playbookId)
            ->take(5)
            ->values()
            ->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function workItemsForBrand(string $brandId): array
    {
        return collect(self::workItems())
            ->filter(function (array $row) use ($brandId): bool {
                $brand = DemoCatalog::brand();

                return ($row['brand'] ?? '') === ($brand['name'] ?? '')
                    || str_contains((string) ($row['id'] ?? ''), $brandId);
            })
            ->values()
            ->all();
    }
}
