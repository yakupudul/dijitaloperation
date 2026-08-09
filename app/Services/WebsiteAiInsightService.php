<?php

namespace App\Services;

use App\Models\DigitalAsset;
use App\Models\Run;
use MoxDop\Website\Ai\WebsiteAiRecommendationConfig;
use MoxDop\Website\Ai\WebsiteAiRecommendationService;

/**
 * Backward-compatible Core facade for Website AI recommendation intelligence.
 *
 * Website-specific orchestration lives in app-modules/website (WebsiteAiRecommendationService).
 * This class remains for historical call sites and tests.
 */
class WebsiteAiInsightService
{
    public const MODULE_ID = WebsiteAiRecommendationConfig::MODULE_ID;

    public const EVIDENCE_TYPE_AI_INSIGHT = WebsiteAiRecommendationConfig::EVIDENCE_TYPE_AI_INSIGHT;

    public function __construct(
        private readonly WebsiteAiRecommendationService $recommendations,
    ) {}

    /**
     * Interpret open (or selected) Website Findings and persist ai_insight Evidence on a Run.
     *
     * @param  list<int>|null  $findingIds
     */
    public function interpret(DigitalAsset $asset, ?array $findingIds = null): Run
    {
        $result = $this->recommendations->analyze($asset, $findingIds);

        return $result['run'];
    }
}
