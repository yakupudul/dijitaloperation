<?php

namespace App\Services\Evidence;

use App\Services\Formulas\FormulaRegistryLoader;
use App\Support\Evidence\EvidenceDefinition;
use RuntimeException;

/**
 * Core Evidence Definition registry (V1). Definitions are code-owned, not EAV facts.
 */
final class EvidenceDefinitionRegistry
{
    public const string VERSION = 'v1';

    /**
     * @var list<EvidenceDefinition>|null
     */
    private ?array $definitions = null;

    public function __construct(
        private readonly FormulaRegistryLoader $formulas,
    ) {}

    /**
     * @return list<EvidenceDefinition>
     */
    public function all(): array
    {
        $this->ensureLoaded();

        return $this->definitions;
    }

    public function get(string $id): EvidenceDefinition
    {
        foreach ($this->all() as $definition) {
            if ($definition->id === $id) {
                return $definition;
            }
        }

        throw new RuntimeException("Unknown Evidence definition [{$id}].");
    }

    public function version(): string
    {
        return self::VERSION;
    }

    private function ensureLoaded(): void
    {
        if ($this->definitions !== null) {
            return;
        }

        $definitions = [
            new EvidenceDefinition(
                id: 'gsc.property.period_comparison',
                statementKind: 'period_comparison',
                titleTemplate: 'Search Console clicks and impressions versus the previous comparable period',
                sourceModule: 'search-console',
                provider: 'SEARCH_CONSOLE',
                datasetId: 'gsc_property_daily',
                physicalTable: 'gsc_property_daily',
                resourceType: 'search_console',
                bindingCapability: 'search_console',
                grainColumn: 'site_url',
                metricFields: ['clicks', 'impressions'],
                formulaIds: ['FORMULA_PERIOD_RELATIVE_CHANGE', 'FORMULA_GSC_CTR'],
                defaultPeriodDays: 28,
            ),
            new EvidenceDefinition(
                id: 'ga4.property.period_comparison',
                statementKind: 'period_comparison',
                titleTemplate: 'GA4 sessions versus the previous comparable period',
                sourceModule: 'ga4',
                provider: 'GA4',
                datasetId: 'ga4_property_daily',
                physicalTable: 'ga4_property_daily',
                resourceType: 'ga4',
                bindingCapability: 'ga4',
                grainColumn: 'property_id',
                metricFields: ['sessions', 'activeUsers'],
                formulaIds: ['FORMULA_PERIOD_RELATIVE_CHANGE'],
                defaultPeriodDays: 28,
            ),
            new EvidenceDefinition(
                id: 'google_ads.account.period_comparison',
                statementKind: 'period_comparison',
                titleTemplate: 'Google Ads conversions and spend versus the previous comparable period',
                sourceModule: 'google-ads',
                provider: 'GOOGLE_ADS',
                datasetId: 'google_ads_account_daily',
                physicalTable: 'google_ads_account_daily',
                resourceType: 'google_ads',
                bindingCapability: 'google_ads',
                grainColumn: 'customer_id',
                metricFields: ['conversions', 'cost_amount', 'clicks', 'impressions'],
                formulaIds: ['FORMULA_PERIOD_RELATIVE_CHANGE'],
                defaultPeriodDays: 28,
            ),
        ];

        foreach ($definitions as $definition) {
            foreach ($definition->formulaIds as $formulaId) {
                $this->formulas->formula($formulaId);
            }
        }

        $this->definitions = $definitions;
    }
}
