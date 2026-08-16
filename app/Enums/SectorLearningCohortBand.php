<?php

namespace App\Enums;

/**
 * Consumer-safe cohort size bands (exact counts stay internal).
 */
enum SectorLearningCohortBand: string
{
    case Band5To9 = '5-9';
    case Band10To19 = '10-19';
    case Band20To49 = '20-49';
    case Band50Plus = '50+';
    case BelowDisclosure = 'below_disclosure';

    public static function fromDistinctBrandCount(int $count): self
    {
        if ($count < 5) {
            return self::BelowDisclosure;
        }
        if ($count <= 9) {
            return self::Band5To9;
        }
        if ($count <= 19) {
            return self::Band10To19;
        }
        if ($count <= 49) {
            return self::Band20To49;
        }

        return self::Band50Plus;
    }
}
