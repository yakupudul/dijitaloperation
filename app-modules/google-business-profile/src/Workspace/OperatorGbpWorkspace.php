<?php

namespace MoxDop\GoogleBusinessProfile\Workspace;

use App\Contracts\GbpOperatorWorkspace as GbpOperatorWorkspaceContract;
use App\Models\CoreAssetBinding;
use App\Models\DigitalAsset;
use App\Models\Evidence;
use App\Models\Run;
use App\Support\Reality\UnavailableWorkspaceShells;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use MoxDop\GoogleBusinessProfile\Collection\GbpLocationBoundCollector;

/**
 * Operator presenter for a bound Google Business Profile location. Reads the provider tables the bound
 * collector fills (gbp_location_snapshots, gbp_performance_daily, gbp_search_keywords_monthly, gbp_reviews,
 * gbp_media, gbp_posts, attribute/service snapshots) by the bound external resource. A collection run that
 * ended "partial" still counts: every dataset that arrived is shown, missing ones are named.
 * The legacy connection-probe Evidence is only a fallback for the profile header.
 */
final class OperatorGbpWorkspace implements GbpOperatorWorkspaceContract
{
    /** @var array<string, string> Google daily metric → operator group */
    private const METRIC_GROUPS = [
        'BUSINESS_IMPRESSIONS_DESKTOP_SEARCH' => 'search_views',
        'BUSINESS_IMPRESSIONS_MOBILE_SEARCH' => 'search_views',
        'BUSINESS_IMPRESSIONS_DESKTOP_MAPS' => 'maps_views',
        'BUSINESS_IMPRESSIONS_MOBILE_MAPS' => 'maps_views',
        'CALL_CLICKS' => 'calls',
        'BUSINESS_DIRECTION_REQUESTS' => 'directions',
        'WEBSITE_CLICKS' => 'website_clicks',
        'BUSINESS_CONVERSATIONS' => 'messages',
        'BUSINESS_BOOKINGS' => 'bookings',
        'BUSINESS_FOOD_ORDERS' => 'bookings',
    ];

    private const STARS = ['ONE' => 1, 'TWO' => 2, 'THREE' => 3, 'FOUR' => 4, 'FIVE' => 5];

    /** @return array<string, mixed> */
    public function for(DigitalAsset $asset, int $days = 28): array
    {
        $days = max(7, min(180, $days));
        $data = UnavailableWorkspaceShells::gbp((string) $asset->id);
        $binding = CoreAssetBinding::query()
            ->with('externalResource.integration')
            ->where('digital_asset_id', $asset->id)
            ->where('capability', GbpLocationBoundCollector::CAPABILITY)
            ->where('status', CoreAssetBinding::STATUS_ACTIVE)
            ->orderByDesc('id')
            ->first();
        $resource = $binding?->externalResource;
        $resourceId = $resource?->id !== null ? (int) $resource->id : null;

        $snapshot = $resourceId !== null
            ? DB::table('gbp_location_snapshots')->where('external_resource_id', $resourceId)->orderByDesc('captured_at')->orderByDesc('id')->first()
            : null;
        $lastRun = $binding !== null ? $this->lastRun($asset, $binding, $snapshot) : null;
        $evidence = $binding !== null && $snapshot === null ? $this->probeEvidence($asset, $binding) : [];

        $bound = $binding !== null;
        $hasData = $snapshot !== null || ($evidence['ok'] ?? false) === true;

        $title = $this->string($snapshot->title ?? null) ?? $this->string($evidence['title'] ?? null) ?? ($resource?->display_name ?: $asset->name);
        $address = $this->addressLine($snapshot !== null ? $this->decode($snapshot->storefront_address) : ($evidence['storefront_address'] ?? null));
        $website = $this->string($snapshot->website_uri ?? null) ?? $this->string($evidence['website_uri'] ?? null);
        $phone = $snapshot !== null ? $this->primaryPhone($this->decode($snapshot->phone_numbers)) : $this->string($evidence['primary_phone'] ?? null);
        $category = $this->string($snapshot->primary_category ?? null) ?? $this->string($evidence['primary_category'] ?? null);
        $lastData = $snapshot !== null ? CarbonImmutable::parse((string) $snapshot->captured_at) : null;

        $data['migration_mode'] = $hasData ? 'real' : ($bound ? 'configured' : 'unavailable');
        $data['identity'] = array_merge($data['identity'] ?? [], [
            'eyebrow' => 'Google Business Profile',
            'title' => $title ?: 'Google Business Profile',
            'brand' => $asset->brand?->name ?? '—',
            'brand_id' => $asset->brand_id,
            'brand_name' => $asset->brand?->name ?? '—',
            'status_key' => $hasData ? 'connected' : ($bound ? 'needs_collection' : 'not_configured'),
            'location_line' => $address ?: '—',
            'last_refresh' => $lastData?->diffForHumans() ?? $lastRun?->finished_at?->diffForHumans(),
            'maps_uri' => $this->string($snapshot->maps_uri ?? null),
        ]);

        $profile = $snapshot !== null ? $this->profileDetails($snapshot, $resourceId) : [];
        $fields = [
            $this->field('business_name', $title),
            $this->field('primary_category', $category),
            $this->field('website', $website),
            $this->field('primary_phone', $phone),
            $this->field('address', $address),
        ];
        $present = count(array_filter($fields, fn (array $field): bool => $field['state'] === 'present'));

        $data['profile_coverage'] = [
            'present' => $present,
            'total_reviewed' => count($fields),
            'need_attention' => $hasData ? count($fields) - $present : 0,
            'unavailable' => $hasData ? 0 : count($fields),
            'groups' => [],
        ];

        $data['profile'] = array_merge($data['profile'] ?? [], [
            'fields' => $fields,
            'categories' => [
                'primary' => $category ?: '—',
                'additional' => $profile['additional_categories'] ?? [],
                'offering_map' => [],
            ],
            'services' => $profile['services'] ?? [],
            'location' => array_merge($data['profile']['location'] ?? [], [
                'address' => $address ?: '—',
                'lat' => null,
                'lng' => null,
                'website_location_page' => $website ?: '—',
            ]),
            'description' => $profile['description'] ?? '',
            'hours' => $profile['hours'] ?? [],
            'attributes_set' => $profile['attributes_set'] ?? null,
            'attributes_unset' => $profile['attributes_unset'] ?? [],
            'completeness' => $snapshot !== null ? $this->completeness($profile, $fields) : null,
        ]);

        $performance = $resourceId !== null ? $this->performance($resourceId, $days) : ['available' => false];
        $keywords = $resourceId !== null ? $this->keywords($resourceId) : ['available' => false, 'items' => []];
        $reviews = $resourceId !== null ? $this->reviews($resourceId, $snapshot) : ['available' => false];
        $content = $resourceId !== null ? $this->content($resourceId) : ['available' => false];

        $data['performance_live'] = $performance;
        $data['keywords_live'] = $keywords;
        $data['reviews_live'] = $reviews;
        $data['content_live'] = $content;

        $data['connection'] = [
            'bound' => $bound,
            'binding_id' => $binding?->id,
            'resource_id' => $resource?->id,
            'resource_name' => $resource?->display_name ?: $resource?->external_id,
            'external_id' => $resource?->external_id,
            'integration_name' => $resource?->integration?->name,
            'last_run_status' => $lastRun?->status,
            'last_run_label' => $this->runLabel($lastRun?->status),
            'last_run_human' => $lastRun?->finished_at?->diffForHumans() ?? $lastRun?->started_at?->diffForHumans(),
            'last_error' => data_get($lastRun?->metadata, 'safe_error'),
            'last_error_hint' => self::errorHint((string) data_get($lastRun?->metadata, 'safe_error')),
        ];

        $data['unsupported_live_capabilities'] = array_values(array_filter([
            ($reviews['available'] ?? false) ? null : 'reviews',
            ($performance['available'] ?? false) ? null : 'performance',
            'local_visibility',
            ($content['available'] ?? false) ? null : 'media',
        ]));

        return $data;
    }

    /** Latest run for this binding, or the run that produced the newest snapshot (resource-first runs have no asset). */
    private function lastRun(DigitalAsset $asset, CoreAssetBinding $binding, ?object $snapshot): ?Run
    {
        $bindingRun = Run::query()
            ->where('digital_asset_id', $asset->id)
            ->where('core_asset_binding_id', $binding->id)
            ->where('module_id', GbpLocationBoundCollector::MODULE_ID)
            ->latest('id')
            ->first();
        $snapshotRun = $snapshot !== null && isset($snapshot->run_id) ? Run::query()->find((int) $snapshot->run_id) : null;

        return collect([$bindingRun, $snapshotRun])->filter()->sortByDesc('id')->first();
    }

    /** @return array<string, mixed> */
    private function probeEvidence(DigitalAsset $asset, CoreAssetBinding $binding): array
    {
        $evidence = Evidence::query()
            ->where('digital_asset_id', $asset->id)
            ->where('type', GbpLocationBoundCollector::EVIDENCE_TYPE)
            ->whereHas('run', fn ($query) => $query
                ->whereIn('status', ['completed', 'partial'])
                ->where('core_asset_binding_id', $binding->id))
            ->latest('observed_at')
            ->latest('id')
            ->first();

        return is_array($evidence?->payload) ? $evidence->payload : [];
    }

    /** @return array<string, mixed> */
    private function profileDetails(object $snapshot, ?int $resourceId): array
    {
        $categories = $this->decode($snapshot->additional_categories);
        $additional = array_values(array_filter(array_map(
            static fn ($c): ?string => is_array($c) ? ($c['displayName'] ?? $c['name'] ?? null) : null,
            (array) ($categories['additionalCategories'] ?? []),
        )));
        $hours = (array) ($this->decode($snapshot->regular_hours)['periods'] ?? []);
        $days = [];
        foreach ($hours as $period) {
            if (is_array($period) && isset($period['openDay'])) {
                $days[(string) $period['openDay']] = true;
            }
        }

        $attributesSet = null;
        $attributesUnset = [];
        $services = [];
        if ($resourceId !== null) {
            $attributeRow = DB::table('gbp_attribute_snapshots')->where('external_resource_id', $resourceId)->orderByDesc('captured_at')->orderByDesc('id')->first();
            if ($attributeRow !== null) {
                $set = [];
                foreach ((array) ($this->decode($attributeRow->attributes)['attributes'] ?? []) as $attribute) {
                    if (is_array($attribute) && isset($attribute['name'])) {
                        $set[] = (string) preg_replace('~^.*attributes/~', '', (string) $attribute['name']);
                    }
                }
                $attributesSet = count($set);
                foreach ((array) $this->decode($attributeRow->available_attributes) as $meta) {
                    if (! is_array($meta) || ($meta['deprecated'] ?? false)) {
                        continue;
                    }
                    $id = (string) preg_replace('~^.*attributes/~', '', (string) ($meta['parent'] ?? ''));
                    if ($id !== '' && ! in_array($id, $set, true)) {
                        $attributesUnset[] = (string) ($meta['displayName'] ?? $id);
                    }
                }
            }
            $serviceRow = DB::table('gbp_service_snapshots')->where('external_resource_id', $resourceId)->orderByDesc('captured_at')->orderByDesc('id')->first();
            foreach ((array) ($serviceRow !== null ? $this->decode($serviceRow->service_items) : []) as $item) {
                if (is_array($item) && isset($item['freeFormServiceItem']['label']['displayName'])) {
                    $services[] = (string) $item['freeFormServiceItem']['label']['displayName'];
                } elseif (is_array($item) && isset($item['structuredServiceItem']['serviceTypeId'])) {
                    $services[] = str_replace(['job_type_id:', '_'], ['', ' '], (string) $item['structuredServiceItem']['serviceTypeId']);
                }
            }
        }

        return [
            'additional_categories' => $additional,
            'description' => (string) ($this->decode($snapshot->profile)['description'] ?? ''),
            'hours' => array_keys($days),
            'special_hours' => (array) ($this->decode($snapshot->special_hours)['specialHourPeriods'] ?? []) !== [],
            'attributes_set' => $attributesSet,
            'attributes_unset' => array_values(array_unique($attributesUnset)),
            'services' => array_values(array_unique(array_filter($services))),
        ];
    }

    /**
     * @param  array<string, mixed>  $profile
     * @param  list<array{key: string, value: string, state: string}>  $fields
     * @return array{score: int, items: list<array{key: string, done: bool}>}
     */
    private function completeness(array $profile, array $fields): array
    {
        $byKey = collect($fields)->keyBy('key');
        $items = [
            ['key' => 'primary_category', 'done' => $byKey['primary_category']['state'] === 'present'],
            ['key' => 'additional_categories', 'done' => ($profile['additional_categories'] ?? []) !== []],
            ['key' => 'description', 'done' => mb_strlen((string) ($profile['description'] ?? '')) >= 250],
            ['key' => 'website', 'done' => $byKey['website']['state'] === 'present'],
            ['key' => 'primary_phone', 'done' => $byKey['primary_phone']['state'] === 'present'],
            ['key' => 'address', 'done' => $byKey['address']['state'] === 'present'],
            ['key' => 'hours', 'done' => count($profile['hours'] ?? []) >= 5],
            ['key' => 'services', 'done' => ($profile['services'] ?? []) !== []],
            ['key' => 'attributes', 'done' => ($profile['attributes_set'] ?? 0) >= 3],
        ];
        $done = count(array_filter($items, static fn (array $item): bool => $item['done']));

        return ['score' => (int) round($done / count($items) * 100), 'items' => $items];
    }

    /** @return array<string, mixed> */
    private function performance(int $resourceId, int $days): array
    {
        $latest = DB::table('gbp_performance_daily')->where('external_resource_id', $resourceId)->max('reporting_date');
        if ($latest === null) {
            return ['available' => false];
        }
        $end = CarbonImmutable::parse((string) $latest)->startOfDay();
        $currentStart = $end->subDays($days - 1);
        $previousStart = $end->subDays(2 * $days - 1);
        $rows = DB::table('gbp_performance_daily')
            ->where('external_resource_id', $resourceId)
            ->whereBetween('reporting_date', [$previousStart->toDateString(), $end->toDateString().' 23:59:59'])
            ->get(['reporting_date', 'metric', 'value']);

        $groups = array_values(array_unique(self::METRIC_GROUPS));
        $current = array_fill_keys($groups, 0);
        $previous = array_fill_keys($groups, 0);
        $seen = [];
        $previousDates = [];
        $daily = [];
        foreach ($rows as $row) {
            $group = self::METRIC_GROUPS[(string) $row->metric] ?? null;
            if ($group === null) {
                continue;
            }
            $date = substr((string) $row->reporting_date, 0, 10);
            $seen[$group] = true;
            if ($date >= $currentStart->toDateString()) {
                $current[$group] += (int) $row->value;
                if (in_array($group, ['search_views', 'maps_views'], true)) {
                    $daily[$date]['views'] = ($daily[$date]['views'] ?? 0) + (int) $row->value;
                } elseif (in_array($group, ['calls', 'directions', 'website_clicks', 'messages', 'bookings'], true)) {
                    $daily[$date]['actions'] = ($daily[$date]['actions'] ?? 0) + (int) $row->value;
                }
            } else {
                $previous[$group] += (int) $row->value;
                $previousDates[$date] = true;
            }
        }
        ksort($daily);
        $hasPrevious = count($previousDates) >= $days - 3;
        $metrics = [];
        foreach ($groups as $group) {
            if (! isset($seen[$group])) {
                continue;
            }
            $metrics[$group] = [
                'current' => $current[$group],
                'previous' => $hasPrevious ? $previous[$group] : null,
                'change_pct' => $hasPrevious && $previous[$group] > 0 ? (int) round(($current[$group] / $previous[$group] - 1) * 100) : null,
            ];
        }
        $views = ($current['search_views'] ?? 0) + ($current['maps_views'] ?? 0);
        $actions = ($current['calls'] ?? 0) + ($current['directions'] ?? 0) + ($current['website_clicks'] ?? 0) + ($current['messages'] ?? 0) + ($current['bookings'] ?? 0);

        return [
            'available' => true,
            'days' => $days,
            'from' => $currentStart->toDateString(),
            'to' => $end->toDateString(),
            'has_previous' => $hasPrevious,
            'metrics' => $metrics,
            'views' => $views,
            'actions' => $actions,
            'action_rate' => $views > 0 ? round($actions / $views * 100, 1) : null,
            'series' => [
                'dates' => array_keys($daily),
                'views' => array_map(static fn (array $d): int => (int) ($d['views'] ?? 0), array_values($daily)),
                'actions' => array_map(static fn (array $d): int => (int) ($d['actions'] ?? 0), array_values($daily)),
            ],
        ];
    }

    /** @return array{available: bool, months: int, from: ?string, to: ?string, items: list<array{keyword: string, impressions: int, below_threshold: bool}>} */
    private function keywords(int $resourceId): array
    {
        $latest = DB::table('gbp_search_keywords_monthly')->where('external_resource_id', $resourceId)->max('month_start');
        if ($latest === null) {
            return ['available' => false, 'months' => 0, 'from' => null, 'to' => null, 'items' => []];
        }
        $months = 3;
        $to = CarbonImmutable::parse((string) $latest);
        $from = $to->subMonthsNoOverflow($months - 1)->startOfMonth();
        $totals = [];
        $thresholdOnly = [];
        foreach (DB::table('gbp_search_keywords_monthly')->where('external_resource_id', $resourceId)->where('month_start', '>=', $from->toDateString())->get() as $row) {
            $keyword = mb_strtolower(trim((string) $row->search_keyword));
            if ($keyword === '') {
                continue;
            }
            if ($row->impressions === null) {
                $thresholdOnly[$keyword] = true;

                continue;
            }
            $totals[$keyword] = ($totals[$keyword] ?? 0) + (int) $row->impressions;
        }
        arsort($totals);
        $items = [];
        foreach (array_slice($totals, 0, 30, true) as $keyword => $impressions) {
            $items[] = ['keyword' => $keyword, 'impressions' => $impressions, 'below_threshold' => false];
        }

        return [
            'available' => true,
            'months' => $months,
            'from' => $from->toDateString(),
            'to' => $to->toDateString(),
            'items' => $items,
            'below_threshold_count' => count(array_diff_key($thresholdOnly, $totals)),
        ];
    }

    /** @return array<string, mixed> */
    private function reviews(int $resourceId, ?object $snapshot): array
    {
        $rows = DB::table('gbp_reviews')->where('external_resource_id', $resourceId)->orderByDesc('create_time')->get(['id', 'reviewer', 'star_rating', 'comment', 'create_time', 'review_reply']);
        if ($rows->isEmpty()) {
            return ['available' => false];
        }
        $recentFrom = CarbonImmutable::now()->subDays(90)->toDateString();
        $ratings = [];
        $recent = [];
        $replied = 0;
        $unansweredRecent = 0;
        $distribution = array_fill(1, 5, 0);
        $latest = [];
        $replyHours = [];
        foreach ($rows as $row) {
            $rating = self::STARS[strtoupper((string) $row->star_rating)] ?? null;
            $date = substr((string) $row->create_time, 0, 10);
            $hasReply = $row->review_reply !== null && $row->review_reply !== 'null';
            $replied += $hasReply ? 1 : 0;
            // Faz 14: response speed = hours between the review and the owner's reply (last 100 replied reviews).
            $replyAt = $hasReply ? ($this->decode($row->review_reply)['updateTime'] ?? null) : null;
            if (is_string($replyAt) && $row->create_time !== null && count($replyHours) < 100) {
                $replyHours[] = max(0, (int) round((strtotime($replyAt) - strtotime((string) $row->create_time)) / 3600));
            }
            if ($rating !== null) {
                $ratings[] = $rating;
                $distribution[$rating]++;
            }
            if ($date !== '' && $date >= $recentFrom) {
                if ($rating !== null) {
                    $recent[] = $rating;
                }
                $unansweredRecent += $hasReply ? 0 : 1;
            }
            if (count($latest) < 25) {
                $latest[] = [
                    'id' => (int) $row->id,
                    'reviewer' => (string) ($this->decode($row->reviewer)['displayName'] ?? '—'),
                    'rating' => $rating,
                    'comment' => $this->string($row->comment) ?? '',
                    'date' => $date,
                    'replied' => $hasReply,
                ];
            }
        }
        $total = $rows->count();
        $avg = static fn (array $values): ?float => $values !== [] ? round(array_sum($values) / count($values), 2) : null;

        return [
            'available' => true,
            'total' => $snapshot?->total_review_count !== null ? (int) $snapshot->total_review_count : $total,
            'average' => $snapshot?->average_rating !== null ? round((float) $snapshot->average_rating, 2) : $avg($ratings),
            'recent_count' => count($recent),
            'recent_average' => $avg($recent),
            'reply_rate' => $total > 0 ? (int) round($replied / $total * 100) : null,
            'unanswered_recent' => $unansweredRecent,
            'reply_hours_median' => $replyHours === [] ? null : (function (array $hours): int {
                sort($hours);

                return $hours[intdiv(count($hours), 2)];
            })($replyHours),
            'distribution' => array_reverse($distribution, true),
            'latest' => $latest,
        ];
    }

    /** @return array<string, mixed> */
    private function content(int $resourceId): array
    {
        $media = DB::table('gbp_media')->where('external_resource_id', $resourceId)->get(['media_format', 'create_time']);
        $posts = DB::table('gbp_posts')->where('external_resource_id', $resourceId)->get(['create_time', 'state']);
        $photos = $media->filter(static fn (object $row): bool => strtoupper((string) $row->media_format) !== 'VIDEO');
        $recentFrom = CarbonImmutable::now()->subDays(90)->toDateTimeString();

        return [
            'available' => $media->isNotEmpty() || $posts->isNotEmpty(),
            'photos' => $photos->count(),
            'videos' => $media->count() - $photos->count(),
            'last_photo' => $photos->max('create_time') !== null ? substr((string) $photos->max('create_time'), 0, 10) : null,
            'photos_90d' => $photos->filter(static fn (object $row): bool => (string) $row->create_time >= $recentFrom)->count(),
            'posts' => $posts->count(),
            'last_post' => $posts->max('create_time') !== null ? substr((string) $posts->max('create_time'), 0, 10) : null,
            'posts_90d' => $posts->filter(static fn (object $row): bool => (string) $row->create_time >= $recentFrom)->count(),
        ];
    }

    private function runLabel(?string $status): ?string
    {
        return match ($status) {
            null => null,
            'completed' => __('operator_gbp.run_status.completed'),
            'partial' => __('operator_gbp.run_status.partial'),
            'failed' => __('operator_gbp.run_status.failed'),
            'running', 'queued' => __('operator_gbp.run_status.running'),
            default => $status,
        };
    }

    /** @return array{key:string,value:string,state:string} */
    private function field(string $key, ?string $value): array
    {
        $present = filled($value);

        return [
            'key' => $key,
            'value' => $present ? (string) $value : '—',
            'state' => $present ? 'present' : 'missing',
        ];
    }

    private function primaryPhone(array $phones): ?string
    {
        return $this->string($phones['primaryPhone'] ?? null);
    }

    private function addressLine(mixed $address): ?string
    {
        if (! is_array($address)) {
            return null;
        }

        $lines = $address['address_lines'] ?? $address['addressLines'] ?? [];
        $parts = array_merge(
            is_array($lines) ? $lines : [],
            array_filter([
                $this->string($address['locality'] ?? null),
                $this->string($address['administrative_area'] ?? $address['administrativeArea'] ?? null),
                $this->string($address['postal_code'] ?? $address['postalCode'] ?? null),
                $this->string($address['region_code'] ?? $address['regionCode'] ?? null),
            ]),
        );

        $parts = array_values(array_unique(array_filter(array_map(
            fn ($part) => is_string($part) ? trim($part) : null,
            $parts,
        ))));

        return $parts === [] ? null : implode(', ', $parts);
    }

    /** @return array<mixed> */
    private function decode(mixed $raw): array
    {
        if (is_array($raw)) {
            return $raw;
        }
        if (! is_string($raw) || $raw === '') {
            return [];
        }
        $decoded = json_decode($raw, true);

        return is_array($decoded) ? $decoded : [];
    }

    private function string(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    /** What the owner should do for the usual Google errors (the raw reason is shown next to it). */
    public static function errorHint(string $error): ?string
    {
        return match (true) {
            $error === '' => null,
            str_contains($error, 'SERVICE_DISABLED') || str_contains($error, 'has not been used') => 'Google Cloud projesinde ilgili Business Profile API kapalı; API Kitaplığı\'ndan etkinleştirin.',
            str_contains($error, 'HTTP 403') => 'Yetki yok: hesabın bu konumda sahip/yönetici olması veya Google\'ın Business Profile API erişim onayı gerekiyor.',
            str_contains($error, 'HTTP 429') || str_contains($error, 'pacing') => 'Google istek sınırı; bir sonraki toplamada kendiliğinden tekrar denenir.',
            str_contains($error, 'HTTP 404') => 'Konum bulunamadı; konum silinmiş veya başka hesaba taşınmış olabilir.',
            default => null,
        };
    }
}
