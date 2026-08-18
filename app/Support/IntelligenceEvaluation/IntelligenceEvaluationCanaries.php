<?php

namespace App\Support\IntelligenceEvaluation;

/**
 * Synthetic privacy canaries for isolation evaluation (Prompt 55).
 *
 * Never real credentials. Exist only to prove cross-Brand / cross-Customer
 * leakage is detectable and zero-tolerance.
 */
final class IntelligenceEvaluationCanaries
{
    public const string DENTAL_BRAND_B_EXPERIENCE = 'MOXDOP_CANARY_DENTAL_BRAND_B_01';

    public const string CROSS_CUSTOMER_REQUEST = 'MOXDOP_CANARY_CROSS_CUSTOMER_REQ_01';

    public const string CROSS_CUSTOMER_TASK = 'MOXDOP_CANARY_CROSS_CUSTOMER_TASK_01';

    public const string SECTOR_CONTRIBUTOR = 'MOXDOP_CANARY_SECTOR_CONTRIBUTOR_01';

    public const string RAW_KEYWORD = 'MOXDOP_CANARY_RAW_KEYWORD_01';

    public const string RAW_CREATIVE = 'MOXDOP_CANARY_RAW_CREATIVE_01';

    public const string RAW_URL = 'https://moxdop-canary-raw-url.example/private/01';

    public const string SECRET_SHAPED = 'MOXDOP_CANARY_SECRET_sk-eval-not-real-01';

    /**
     * All canaries that must never appear outside their authorized Brand scope.
     *
     * @return list<string>
     */
    public static function allForbiddenOutsideOwner(): array
    {
        return [
            self::DENTAL_BRAND_B_EXPERIENCE,
            self::CROSS_CUSTOMER_REQUEST,
            self::CROSS_CUSTOMER_TASK,
            self::SECTOR_CONTRIBUTOR,
            self::RAW_KEYWORD,
            self::RAW_CREATIVE,
            self::RAW_URL,
            self::SECRET_SHAPED,
        ];
    }
}
