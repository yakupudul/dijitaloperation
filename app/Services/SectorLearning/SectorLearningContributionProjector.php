<?php

namespace App\Services\SectorLearning;

use App\Contracts\IntelligenceMemory\SectorIdentityResolver;
use App\Enums\BrandExperienceStatus;
use App\Enums\BrandExperienceSupportStatus;
use App\Enums\SectorLearningPrivacyReasonCode;
use App\Models\Brand;
use App\Models\BrandExperience;
use App\Support\Options\IndustryOptions;
use App\Support\SectorLearning\Dto\InternalSectorContribution;
use App\Support\SectorLearning\Dto\SafeSectorContributionProjection;
use App\Support\SectorLearning\SectorLearningPrivacyPolicy;
use Carbon\CarbonImmutable;

/**
 * Deterministic Brand Experience → safe Sector contribution projection.
 *
 * No AI. Strips identifiers and free text from consumer-safe projection.
 */
final class SectorLearningContributionProjector
{
    public const string VERSION = SectorLearningPrivacyPolicy::PROJECTION_VERSION;

    public function __construct(
        private readonly SectorIdentityResolver $sectorIdentityResolver,
    ) {}

    /**
     * @return array{ok: true, contribution: InternalSectorContribution}|array{ok: false, reasons: list<string>}
     */
    public function project(BrandExperience $experience): array
    {
        $experience->loadMissing(['currentRevision', 'brand.customer']);

        $revision = $experience->currentRevision;
        if ($revision === null) {
            return ['ok' => false, 'reasons' => [SectorLearningPrivacyReasonCode::ContributionNotQualified->value]];
        }

        if ($experience->status !== BrandExperienceStatus::Confirmed) {
            return ['ok' => false, 'reasons' => [SectorLearningPrivacyReasonCode::ContributionNotQualified->value]];
        }

        if (! in_array(
            $revision->support_status,
            [BrandExperienceSupportStatus::Sufficient, BrandExperienceSupportStatus::Partial],
            true
        )) {
            return ['ok' => false, 'reasons' => [SectorLearningPrivacyReasonCode::ContributionNotQualified->value]];
        }

        $brand = $experience->brand;
        if (! $brand instanceof Brand) {
            return ['ok' => false, 'reasons' => [SectorLearningPrivacyReasonCode::ContributionNotQualified->value]];
        }

        $sector = $this->sectorIdentityResolver->resolveForBrand($brand);
        if (! $sector->isPresent() || $sector->aiInferred || ! IndustryOptions::isValid($sector->code)) {
            return ['ok' => false, 'reasons' => [SectorLearningPrivacyReasonCode::SectorUnknown->value]];
        }

        $outcomeAt = $revision->outcome_observed_at;
        if ($outcomeAt === null) {
            return ['ok' => false, 'reasons' => [SectorLearningPrivacyReasonCode::ContributionNotQualified->value]];
        }

        $timeBucket = CarbonImmutable::parse($outcomeAt)->utc()->format('Y-m');

        $projection = new SafeSectorContributionProjection(
            projectionVersion: self::VERSION,
            sectorCode: (string) $sector->code,
            channel: $revision->channel?->value,
            marketCode: $revision->market_code,
            actionKind: $revision->action_kind->value,
            outcomeClarity: $revision->outcome_clarity->value,
            timeBucket: $timeBucket,
            supportStatus: $revision->support_status->value,
            qualityPolicyVersion: (string) $revision->quality_policy_version,
            causalityStatus: $revision->causality_status->value,
            contributionFingerprint: $this->fingerprint(
                experienceId: (int) $experience->id,
                revisionId: (int) $revision->id,
                revisionNumber: (int) $revision->revision_number,
                sectorCode: (string) $sector->code,
                actionKind: $revision->action_kind->value,
                outcomeClarity: $revision->outcome_clarity->value,
                timeBucket: $timeBucket,
            ),
        );

        $consumer = $projection->toConsumerSafeArray();
        foreach (array_keys($consumer) as $key) {
            if (SectorLearningPrivacyPolicy::isBlockedIdentifierKey($key)) {
                return ['ok' => false, 'reasons' => [SectorLearningPrivacyReasonCode::RawIdentifierPresent->value]];
            }
        }

        return [
            'ok' => true,
            'contribution' => new InternalSectorContribution(
                projection: $projection,
                brandExperienceId: (int) $experience->id,
                brandExperienceRevisionId: (int) $revision->id,
                brandId: (int) $experience->brand_id,
                customerId: (int) $experience->customer_id,
            ),
        ];
    }

    private function fingerprint(
        int $experienceId,
        int $revisionId,
        int $revisionNumber,
        string $sectorCode,
        string $actionKind,
        string $outcomeClarity,
        string $timeBucket,
    ): string {
        return hash('sha256', implode('|', [
            self::VERSION,
            $experienceId,
            $revisionId,
            $revisionNumber,
            $sectorCode,
            $actionKind,
            $outcomeClarity,
            $timeBucket,
        ]));
    }
}
