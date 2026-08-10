<?php

namespace MoxDop\GoogleAds\Ai;

use App\Support\Agents\AgentProfileDefinition;
use App\Support\Skills\SkillDefinition;
use App\Support\Skills\SkillEligibilityEvaluator;
use App\Support\Skills\SkillRegistry;

/**
 * Google Ads Agent + Skill assembly helpers for Google Ads AI Guidance.
 */
final class GoogleAdsAgentSkillAssembler
{
    public function __construct(
        private readonly SkillRegistry $skills,
        private readonly SkillEligibilityEvaluator $eligibility,
    ) {}

    /**
     * @param  list<string>  $evidenceTypes
     * @param  array<string, bool>  $contextFlags
     * @return array{
     *     active_skills: list<SkillDefinition>,
     *     skill_evaluations: list<array<string, mixed>>,
     *     skill_signatures: list<string>,
     *     prompt_skills_block: string
     * }
     */
    public function assemble(AgentProfileDefinition $profile, array $evidenceTypes, array $contextFlags = []): array
    {
        $evaluations = [];
        $active = [];

        foreach ($profile->skillSlugs as $slug) {
            $skill = $this->skills->get($slug);
            $evaluation = $this->eligibility->evaluate($skill, $evidenceTypes, $contextFlags);
            $evaluations[] = [
                'slug' => $skill->slug,
                'version' => $skill->version,
                'name' => $skill->name,
                'status' => $evaluation['status'],
                'eligible' => $evaluation['eligible'],
                'missing_evidence' => $evaluation['missing_evidence'],
                'missing_context' => $evaluation['missing_context'],
                'required_capabilities' => $evaluation['required_capabilities'],
                'optional_capabilities' => $evaluation['optional_capabilities'],
            ];

            if ($evaluation['eligible']) {
                $active[] = $skill;
            }
        }

        // account-performance-audit should still run if Findings exist even when others are ineligible.
        // It has no required evidence — always eligible when present in profile.

        return [
            'active_skills' => $active,
            'skill_evaluations' => $evaluations,
            'skill_signatures' => array_map(
                fn (SkillDefinition $skill): string => $skill->signature(),
                $active
            ),
            'prompt_skills_block' => $this->renderSkillsBlock($active, $evaluations),
        ];
    }

    /**
     * @param  list<SkillDefinition>  $active
     * @param  list<array<string, mixed>>  $evaluations
     */
    private function renderSkillsBlock(array $active, array $evaluations): string
    {
        $lines = [];
        $lines[] = 'ACTIVE SKILLS (trusted curated methodology — follow these):';
        if ($active === []) {
            $lines[] = '(none eligible — produce only high-level uncertainty guidance; do not invent domain metrics)';
        }

        foreach ($active as $skill) {
            $lines[] = '### SKILL '.$skill->signature();
            $lines[] = $skill->methodologyForPrompt();
            $lines[] = '';
        }

        $lines[] = 'SKILL ELIGIBILITY SUMMARY:';
        foreach ($evaluations as $row) {
            $status = (string) $row['status'];
            $missing = [];
            if (! empty($row['missing_evidence'])) {
                $missing[] = 'missing_evidence='.implode(',', $row['missing_evidence']);
            }
            if (! empty($row['missing_context'])) {
                $missing[] = 'missing_context='.implode(',', $row['missing_context']);
            }
            $suffix = $missing === [] ? '' : ' ('.implode('; ', $missing).')';
            $lines[] = '- '.$row['slug'].'@'.$row['version'].': '.$status.$suffix;
        }

        $lines[] = '';
        $lines[] = 'Capability fields above are METADATA ONLY. Do not call providers or invent Capability Router behavior.';

        return implode("\n", $lines);
    }
}
