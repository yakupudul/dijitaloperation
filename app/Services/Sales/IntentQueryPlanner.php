<?php

namespace App\Services\Sales;

use App\Models\SalesSearchProfile;
use App\Support\Sales\IntentSearchConfig;

final class IntentQueryPlanner
{
    /**
     * @return list<string>
     */
    public function plan(SalesSearchProfile $profile): array
    {
        $includes = is_array($profile->include_concepts) ? $profile->include_concepts : [];
        $queries = [];

        foreach ($includes as $phrase) {
            if (! is_string($phrase)) {
                continue;
            }

            $normalized = trim($phrase);
            if ($normalized === '') {
                continue;
            }

            $queries[] = $normalized;
            if (count($queries) >= IntentSearchConfig::maxQueries()) {
                break;
            }
        }

        return array_values(array_unique($queries));
    }
}
