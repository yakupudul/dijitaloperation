<?php

namespace Tests\Feature\DataPool;

use App\Services\DataPool\Integrity\DataIntegrityRegistryLoader;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class MetaAdsProfessionalIntegrityProfileOverlayTest extends TestCase
{
    #[Test]
    public function professional_v2_datasets_receive_runtime_integrity_profiles(): void
    {
        $loader = app(DataIntegrityRegistryLoader::class);

        $expected = [
            'meta_account_daily',
            'meta_campaign_daily',
            'meta_adset_daily',
            'meta_ad_daily',
            'meta_typed_action_daily',
            'meta_video_engagement_daily',
            'meta_analysis_breakdown_daily',
            'meta_hourly_daily',
            'meta_ad_snapshot',
            'meta_adset_targeting_snapshot',
            'meta_conversion_source_snapshot',
            'meta_change_event',
        ];

        foreach ($expected as $datasetId) {
            $profile = $loader->profile($datasetId);

            $this->assertNotNull($profile, "Missing integrity profile for {$datasetId}");
            $this->assertSame('META_ADS', $profile['provider_or_source']);
            $this->assertSame('PHYSICAL_TABLE', $profile['storage_disposition']);
            $this->assertNotEmpty($profile['natural_key']);
            $this->assertContains('natural_key_duplicates', $profile['required_checks']);
            $this->assertContains('materialization_reconciliation', $profile['required_checks']);
            $this->assertContains('contract_completeness', $profile['required_checks']);
            $this->assertNotContains('write_receipt_accounting', $profile['required_checks']);
            $this->assertNotContains('coverage_intervals', $profile['required_checks']);
            $this->assertSame('MetaAdsUiDatasetGate', $profile['metadata']['coverage_gate'] ?? null);
        }
    }

    #[Test]
    public function existing_campaign_daily_profile_is_normalized_to_professional_v2_policy(): void
    {
        $profile = app(DataIntegrityRegistryLoader::class)->profile('meta_campaign_daily');

        $this->assertNotNull($profile);
        $this->assertNotContains('write_receipt_accounting', $profile['required_checks']);
        $this->assertNotContains('coverage_intervals', $profile['required_checks']);
        $this->assertNotContains('write_receipt_accounting', $profile['migration_blocking_checks']);
        $this->assertNotContains('coverage_intervals', $profile['migration_blocking_checks']);
        $this->assertTrue((bool) ($profile['metadata']['professional_v2_policy'] ?? false));
    }

    #[Test]
    public function account_daily_profile_uses_central_contract_natural_key_and_ui_coverage_gate(): void
    {
        $profile = app(DataIntegrityRegistryLoader::class)->profile('meta_account_daily');

        $this->assertNotNull($profile);
        $this->assertSame('meta_account_daily', $profile['physical_table']);
        $this->assertSame(
            ['external_resource_id', 'account_id', 'reporting_date'],
            $profile['natural_key'],
        );
        $this->assertNotContains('coverage_intervals', $profile['required_checks']);
        $this->assertContains('row_accounting', $profile['required_checks']);
        $this->assertSame('INTERVAL_SET', $profile['coverage_mode']);
        $this->assertSame('MetaAdsUiDatasetGate', $profile['metadata']['coverage_gate'] ?? null);
    }

    #[Test]
    public function video_fanout_does_not_use_invalid_one_to_one_row_accounting(): void
    {
        $profile = app(DataIntegrityRegistryLoader::class)->profile('meta_video_engagement_daily');

        $this->assertNotNull($profile);
        $this->assertNotContains('row_accounting', $profile['required_checks']);
        $this->assertNotContains('coverage_intervals', $profile['required_checks']);
        $this->assertSame('INTERVAL_SET', $profile['coverage_mode']);
    }

    #[Test]
    public function snapshot_profiles_use_snapshot_semantics(): void
    {
        foreach (['meta_ad_snapshot', 'meta_adset_targeting_snapshot', 'meta_conversion_source_snapshot', 'meta_change_event'] as $datasetId) {
            $profile = app(DataIntegrityRegistryLoader::class)->profile($datasetId);

            $this->assertNotNull($profile);
            $this->assertSame('snapshot', $profile['history_mode']);
            $this->assertSame('SNAPSHOT', $profile['coverage_mode']);
            $this->assertContains('snapshot_semantics', $profile['required_checks']);
            $this->assertNotContains('coverage_intervals', $profile['required_checks']);
        }
    }

    #[Test]
    public function existing_meta_profiles_are_not_duplicated_by_overlay(): void
    {
        $profiles = collect(app(DataIntegrityRegistryLoader::class)->profiles())
            ->where('dataset_id', 'meta_campaign_daily');

        $this->assertCount(1, $profiles);
    }
}
