<?php

namespace App\Models;

use App\Enums\ProspectSalesIntelligenceStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'prospect_id',
    'prospect_research_run_id',
    'summary',
    'detected_needs',
    'recommended_services',
    'not_recommended_services',
    'sales_priorities',
    'first_meeting_focus',
    'diagnostic_questions',
    'suggested_positioning',
    'uncertainties',
    'overall_confidence',
    'status',
    'metadata',
])]
class ProspectSalesIntelligence extends Model
{
    protected $table = 'prospect_sales_intelligence';

    protected function casts(): array
    {
        return [
            'status' => ProspectSalesIntelligenceStatus::class,
            'detected_needs' => 'array',
            'recommended_services' => 'array',
            'not_recommended_services' => 'array',
            'sales_priorities' => 'array',
            'diagnostic_questions' => 'array',
            'uncertainties' => 'array',
            'metadata' => 'array',
        ];
    }

    /**
     * @return BelongsTo<Prospect, $this>
     */
    public function prospect(): BelongsTo
    {
        return $this->belongsTo(Prospect::class);
    }

    /**
     * @return BelongsTo<ProspectResearchRun, $this>
     */
    public function researchRun(): BelongsTo
    {
        return $this->belongsTo(ProspectResearchRun::class, 'prospect_research_run_id');
    }
}
