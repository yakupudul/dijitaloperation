<?php

namespace App\Services\Operator;

use App\Enums\CustomerStatus;
use App\Enums\CustomerType;
use App\Models\Brand;
use App\Models\Customer;
use App\Models\CustomerContact;
use App\Models\DigitalAsset;
use App\Services\Findings\FindingReadService;
use App\Services\Work\WorkReadService;
use App\Support\DigitalAssetTypes;
use App\Support\Options\CountryOptions;
use App\Support\Options\IndustryOptions;
use Illuminate\Support\Collection;

/**
 * Maps canonical Eloquent portfolio records to the existing operator view arrays.
 * Never injects DemoCatalog / Atlas fallbacks.
 */
final class OperatorPortfolioPresenter
{
    /**
     * @return array<string, mixed>
     */
    public static function customer(Customer $customer): array
    {
        $customer->loadMissing(['brands.digitalAssets', 'responsibleUsers', 'contacts']);

        $typeValue = $customer->type instanceof CustomerType ? $customer->type->value : (string) $customer->type;
        $statusValue = $customer->status instanceof CustomerStatus ? $customer->status->value : (string) $customer->status;
        $responsibleIds = $customer->responsibleUsers->pluck('id')->map(static fn (mixed $id): string => (string) $id)->values()->all();
        $work = self::workForCustomer($customer->id);
        $openFindings = count(app(FindingReadService::class)->forCustomer($customer));

        $industry = $customer->industry;
        $industryLabel = IndustryOptions::label($industry);

        return [
            'id' => (string) $customer->id,
            'name' => $customer->name,
            'legal_name' => $customer->legal_name,
            'type' => $typeValue,
            'type_label' => $typeValue === CustomerType::Individual->value ? 'Individual' : 'Company',
            'status' => $statusValue,
            'status_label' => match ($statusValue) {
                'active' => 'Active',
                'inactive' => 'Inactive',
                'archived' => 'Archived',
                default => ucfirst($statusValue),
            },
            'industry' => $industry,
            'industry_label' => $industryLabel,
            'hq_country' => $customer->hq_country,
            'hq_city' => $customer->hq_city,
            'hq' => $customer->hqDisplay(),
            'hq_display' => $customer->hqDisplay(),
            'services' => is_array($customer->services) ? array_values($customer->services) : [],
            'service_started_at' => $customer->service_started_at?->toDateString(),
            'primary_email' => $customer->primary_email,
            'primary_phone' => $customer->primary_phone,
            'responsible_user_ids' => $responsibleIds,
            'responsible_labels' => $customer->responsibleUsers->pluck('name')->values()->all(),
            'brands_count' => $customer->brands->count(),
            'digital_assets_count' => $customer->brands->sum(fn (Brand $brand): int => $brand->digitalAssets->count()),
            'open_findings' => $openFindings,
            'open_issues' => $openFindings,
            'open_tasks' => $work['open'],
            'overdue_tasks' => $work['overdue'],
            'needs_attention' => $openFindings > 0 || $work['overdue'] > 0,
            'updated_at' => $customer->updated_at?->toDateTimeString(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function brand(Brand $brand): array
    {
        $brand->loadMissing(['customer', 'responsibleUsers', 'digitalAssets', 'intelligenceContext']);

        $assets = $brand->digitalAssets->reject(fn (DigitalAsset $asset): bool => in_array($asset->type, ['domain', 'hosting'], true));
        $responsibleIds = $brand->responsibleUsers->pluck('id')->map(static fn (mixed $id): string => (string) $id)->values()->all();
        $work = self::workForBrand($brand->id);
        $openFindings = count(app(FindingReadService::class)->forBrand($brand));
        $context = $brand->intelligenceContext;
        $completed = 0;
        $total = 8;
        if ($context !== null) {
            foreach (['business_summary', 'business_model', 'positioning'] as $field) {
                if (filled($context->{$field})) {
                    $completed++;
                }
            }
            foreach (['products_services', 'priority_offerings', 'target_audiences', 'business_goals', 'conversion_goals'] as $field) {
                if (is_array($context->{$field}) && $context->{$field} !== []) {
                    $completed++;
                }
            }
            $completed = min($total, $completed);
        }

        return [
            'id' => (string) $brand->id,
            'customer_id' => (string) $brand->customer_id,
            'customer_name' => $brand->customer?->name ?? '—',
            'name' => $brand->name,
            'sector' => $brand->sector,
            'industry' => $brand->sector,
            'sector_label' => IndustryOptions::label($brand->sector),
            'primary_country' => $brand->primary_country,
            'primary_market_label' => CountryOptions::label($brand->primary_country),
            'target_markets' => is_array($brand->target_markets) ? array_values($brand->target_markets) : [],
            'languages' => is_array($brand->languages) ? array_values($brand->languages) : [],
            'description' => $brand->description,
            'audience' => $brand->audience,
            'offerings' => $brand->offerings,
            'competitors' => $brand->competitors,
            'logo_url' => $brand->logo_url,
            'responsible_user_ids' => $responsibleIds,
            'responsible' => $brand->responsibleUsers
                ->map(static fn ($user): array => [
                    'id' => (string) $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                ])
                ->values()
                ->all(),
            'location' => CountryOptions::label($brand->primary_country),
            'assets_count' => $assets->count(),
            'connected_assets' => 0,
            'open_findings' => $openFindings,
            'open_tasks' => $work['open'],
            'overdue_tasks' => $work['overdue'],
            'context_completed' => $completed,
            'context_total' => $total,
            'context_ratio' => $total > 0 ? $completed / $total : 0,
            'needs_attention' => $openFindings > 0 || $work['overdue'] > 0,
            'asset_types' => $assets->pluck('type')->unique()->values()->all(),
            'health' => $openFindings > 0 || $work['overdue'] > 0 ? 'needs_attention' : 'healthy',
            'health_label' => $openFindings > 0 || $work['overdue'] > 0 ? 'Needs attention' : 'Healthy',
            'initials' => collect(explode(' ', (string) $brand->name))
                ->map(static fn (string $part): string => mb_substr($part, 0, 1))
                ->take(2)
                ->implode(''),
            'extra_markets' => max(0, count(is_array($brand->target_markets) ? $brand->target_markets : []) - 1),
            'summary' => [
                'media_spend' => 0,
                'platform_leads' => 0,
                'website_leads' => 0,
                'calls_messages' => 0,
                'currency' => 'TRY',
            ],
        ];
    }

    /**
     * Asset existence is DEFINED only — never Connected / Configured / Fresh from create.
     *
     * @return array<string, mixed>
     */
    public static function asset(DigitalAsset $asset): array
    {
        $asset->loadMissing(['brand.customer']);
        $type = (string) $asset->type;
        $options = DigitalAssetTypes::options();
        $status = $asset->status?->value ?? 'active';
        $openFindings = $asset->relationLoaded('findings')
            ? $asset->findings->where('status', 'open')->count()
            : $asset->findings()->where('status', 'open')->count();

        return [
            'id' => (string) $asset->id,
            'brand_id' => (string) $asset->brand_id,
            'customer_id' => (string) ($asset->brand?->customer_id ?? ''),
            'customer_name' => $asset->brand?->customer?->name ?? '—',
            'brand_name' => $asset->brand?->name ?? '—',
            'name' => $asset->name,
            'type' => $type,
            'type_label' => $options[$type] ?? match ($type) {
                'ga4', 'analytics', 'google_analytics' => 'Google Analytics',
                'gsc', 'search_console' => 'Search Console',
                'gbp', 'google_business_profile' => 'Google Business Profile',
                default => $type,
            },
            'status' => $status,
            'role' => in_array($type, ['domain', 'hosting'], true) ? 'infrastructure' : 'primary_managed',
            'role_label' => in_array($type, ['domain', 'hosting'], true) ? 'Website infrastructure' : 'Primary managed asset',
            'health' => 'healthy',
            'health_label' => 'Defined',
            'connection' => 'not_configured',
            'connection_label' => 'Defined',
            'connection_state' => 'not_connected',
            'connection_state_label' => 'Defined',
            'operational_status' => $status === 'archived' ? 'archived' : ($status === 'inactive' ? 'inactive' : 'setup'),
            'operational_status_label' => $status === 'active' ? 'Defined' : ucfirst($status),
            'data_state' => 'unavailable',
            'data_state_label' => 'Not collected',
            'provenance' => 'Operator defined',
            'open_findings' => $openFindings,
            'open_tasks' => 0,
            'last_update' => 'Never',
            'last_meaningful_activity' => '',
            'primary_metric_label' => 'Status',
            'primary_metric' => 'Defined',
            'route' => self::specialistRoute($type),
            'domain' => $asset->domain,
            'primary_url' => $asset->primary_url,
            'cms' => $asset->cms,
            'site_type' => $asset->site_type,
            'languages' => is_array($asset->languages) ? array_values($asset->languages) : [],
            'target_countries' => is_array($asset->target_countries) ? array_values($asset->target_countries) : [],
            'hosting_context' => $asset->hosting_context,
            'module_id' => $asset->module_id,
            'responsible_user_ids' => [],
            'responsible_users' => [],
            'attention_priority' => $openFindings > 0 ? 'medium' : 'none',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function contact(CustomerContact $contact): array
    {
        return [
            'id' => (string) $contact->id,
            'customer_id' => (string) $contact->customer_id,
            'name' => $contact->name,
            'email' => $contact->email,
            'phone' => $contact->phone,
            'title' => $contact->title,
            'role' => null,
        ];
    }

    public static function specialistRoute(string $type): string
    {
        return match ($type) {
            'website' => 'operator.website',
            'meta_ads' => 'operator.meta.overview',
            'google_ads' => 'operator.google-ads.overview',
            'google_business_profile', 'gbp' => 'operator.gbp',
            'ga4', 'analytics', 'google_analytics' => 'operator.analytics',
            'gsc', 'search_console' => 'operator.search-console',
            'instagram' => 'operator.instagram',
            default => 'operator.assets',
        };
    }

    public static function derivedModuleId(string $type): ?string
    {
        return match ($type) {
            'website' => 'website',
            'meta_ads' => 'meta-ads',
            'google_ads' => 'google-ads',
            'google_business_profile', 'gbp' => 'google-business-profile',
            'ga4', 'analytics', 'google_analytics' => 'analytics',
            'gsc', 'search_console' => 'search-console',
            'instagram' => 'instagram',
            default => null,
        };
    }

    public static function canonicalAssetType(string $wizardType): string
    {
        return match ($wizardType) {
            'gbp' => 'google_business_profile',
            'analytics' => 'ga4',
            'search_console' => 'gsc',
            default => $wizardType,
        };
    }

    /**
     * @param  list<array<string, mixed>>  $assets
     * @return array{managed: int, needs_attention: int, data_issues: int, active_work: int}
     */
    public static function assetsGlance(array $assets): array
    {
        return [
            'managed' => count($assets),
            'needs_attention' => collect($assets)->filter(fn (array $a): bool => ((int) ($a['open_findings'] ?? 0)) > 0)->count(),
            'data_issues' => 0,
            'active_work' => collect($assets)->filter(fn (array $a): bool => ((int) ($a['open_tasks'] ?? 0)) > 0)->count(),
        ];
    }

    /**
     * @param  Collection<int, Brand>  $brands
     * @return array<string, mixed>
     */
    public static function estateMatrix(Collection $brands): array
    {
        $columns = [
            'website' => 'Website',
            'google_business_profile' => 'GBP',
            'google_ads' => 'Google Ads',
            'meta_ads' => 'Meta',
            'ga4' => 'GA4',
            'gsc' => 'GSC',
        ];

        $rows = $brands->map(function (Brand $brand) use ($columns): array {
            $byType = $brand->digitalAssets->keyBy('type');
            $cells = [];
            foreach ($columns as $type => $label) {
                $asset = $byType->get($type);
                if ($asset === null && $type === 'google_business_profile') {
                    $asset = $byType->get('gbp');
                }
                if ($asset === null) {
                    $cells[$type] = ['state' => 'not_configured', 'label' => 'Defined later'];

                    continue;
                }
                $cells[$type] = [
                    'state' => 'present',
                    'label' => 'Defined',
                    'asset_id' => (string) $asset->id,
                    'route' => self::specialistRoute($asset->type),
                ];
            }

            return [
                'brand_id' => (string) $brand->id,
                'brand' => $brand->name,
                'customer' => $brand->customer?->name ?? '—',
                'cells' => $cells,
            ];
        })->values()->all();

        return [
            'columns' => [
                'website' => 'Website',
                'google_business_profile' => 'GBP',
                'google_ads' => 'Google Ads',
                'meta_ads' => 'Meta',
                'ga4' => 'GA4',
                'gsc' => 'GSC',
            ],
            'rows' => $rows,
        ];
    }

    /**
     * @return array{open: int, overdue: int}
     */
    private static function workForCustomer(int $customerId): array
    {
        $items = collect(app(WorkReadService::class)->workItems())
            ->filter(fn (array $row): bool => (int) ($row['customer_id'] ?? 0) === $customerId)
            ->reject(fn (array $row): bool => in_array($row['status'] ?? '', ['completed', 'done', 'declined', 'skipped'], true));

        return [
            'open' => $items->count(),
            'overdue' => $items->where('due_key', 'overdue')->count(),
        ];
    }

    /**
     * @return array{open: int, overdue: int}
     */
    private static function workForBrand(int $brandId): array
    {
        $items = collect(app(WorkReadService::class)->workItems())
            ->filter(fn (array $row): bool => (int) ($row['brand_id'] ?? 0) === $brandId)
            ->reject(fn (array $row): bool => in_array($row['status'] ?? '', ['completed', 'done', 'declined', 'skipped'], true));

        return [
            'open' => $items->count(),
            'overdue' => $items->where('due_key', 'overdue')->count(),
        ];
    }
}
