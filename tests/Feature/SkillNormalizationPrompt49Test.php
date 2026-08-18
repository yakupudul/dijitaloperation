<?php

namespace Tests\Feature;

use App\Models\Finding;
use App\Models\Task;
use App\Support\Skills\BuiltInSkillLoader;
use App\Support\Skills\SkillDefinition;
use App\Support\Skills\SkillDefinitionFingerprint;
use App\Support\Skills\SkillDefinitionValidator;
use App\Support\Skills\SkillEligibilityEvaluator;
use App\Support\Skills\SkillEvidenceRequirement;
use App\Support\Skills\SkillGlobalClaimPolicy;
use App\Support\Skills\SkillRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class SkillNormalizationPrompt49Test extends TestCase
{
    use RefreshDatabase;

    private string $tempSkillRoot;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tempSkillRoot = storage_path('framework/testing/skills-p49-'.uniqid());
        File::ensureDirectoryExists($this->tempSkillRoot);
    }

    protected function tearDown(): void
    {
        if (isset($this->tempSkillRoot) && is_dir($this->tempSkillRoot)) {
            File::deleteDirectory($this->tempSkillRoot);
        }

        parent::tearDown();
    }

    public function test_all_shipped_skills_pass_definition_validator(): void
    {
        $registry = app(SkillRegistry::class);
        $validator = app(SkillDefinitionValidator::class);

        $skills = $registry->all();
        $this->assertCount(23, $skills);

        foreach ($skills as $skill) {
            $this->assertSame([], $validator->validate($skill), $skill->stableKey());
            $this->assertNotSame('', $skill->definitionFingerprint());
            $this->assertSame($skill->module.'.'.$skill->slug, $skill->stableKey());
            $this->assertContains($skill->definitionStatus, [
                SkillDefinition::STATUS_ACTIVE,
                SkillDefinition::STATUS_DRAFT,
                SkillDefinition::STATUS_EXPERIMENTAL,
                SkillDefinition::STATUS_NEEDS_REVIEW,
                SkillDefinition::STATUS_DEPRECATED,
            ]);
            $this->assertNotSame('', $skill->purpose);
            $this->assertNotSame('', $skill->whenToUse);
            $this->assertNotSame('', $skill->doNotUseWhen);
            $this->assertNotSame('', $skill->methodology);
            $this->assertNotEmpty($skill->allowedConclusions);
            $this->assertNotEmpty($skill->effectiveForbiddenClaims());
            $this->assertNotEmpty($skill->successSignals);
            $this->assertNotEmpty($skill->referenceSources);
            $this->assertDoesNotMatchRegularExpression('/<\?php|eval\s*\(/i', $skill->bodyMarkdown);
            $this->assertDoesNotMatchRegularExpression('/\b(seo score|geo score|health score|ai visibility score)\b/i', $skill->methodology);
        }
    }

    public function test_prompt48_ready_candidates_are_normalized_with_provenance(): void
    {
        $registry = app(SkillRegistry::class);

        $expected = [
            'technical-seo-analysis' => 'C1',
            'indexability-analysis' => 'C2',
            'metadata-consistency' => 'C3',
            'gsc-search-demand-review' => 'C7',
            'keyword-opportunity-analysis' => 'C8',
            'ga4-measurement-quality' => 'C11',
        ];

        foreach ($expected as $slug => $candidate) {
            $skill = $registry->getForModule('website', $slug);
            $this->assertSame(SkillDefinition::STATUS_ACTIVE, $skill->definitionStatus);
            $this->assertNotEmpty($skill->researchProvenance);
            $this->assertTrue(
                collect($skill->researchProvenance)->contains(
                    fn (string $line): bool => str_contains($line, $candidate) || str_contains($line, 'Prompt 48')
                ),
                $slug.' missing Prompt 48 provenance'
            );
            $this->assertNotEmpty($skill->abstentionRules);
        }
    }

    public function test_deferred_prompt48_candidates_were_not_invented_as_skills(): void
    {
        $registry = app(SkillRegistry::class);
        $slugs = collect($registry->all())->pluck('slug')->all();

        foreach ([
            'structured-data-audit',
            'internal-linking-analysis',
            'content-quality-review',
            'local-profile-completeness',
            'local-review-intelligence',
            'geo-observation-analysis',
            'claude-seo-technical-audit',
            'open-seo-technical-audit',
        ] as $rejected) {
            $this->assertNotContains($rejected, $slugs);
        }
    }

    public function test_global_forbidden_claims_merge_and_cannot_be_empty(): void
    {
        $global = SkillGlobalClaimPolicy::forbiddenClaims();
        $this->assertNotEmpty($global);

        $effective = SkillGlobalClaimPolicy::effectiveForbiddenClaims(['Skill-specific ban.']);
        $this->assertContains('Skill-specific ban.', $effective);
        $this->assertTrue(count($effective) > count($global) - 1);
        $this->assertTrue(collect($global)->contains(fn (string $c): bool => str_contains(strtolower($c), 'missing')));
        $this->assertTrue(collect($global)->contains(fn (string $c): bool => str_contains(strtolower($c), 'magic')));
    }

    public function test_missing_required_evidence_abstains_and_never_implies_zero(): void
    {
        $skill = app(SkillRegistry::class)->getForModule('website', 'technical-seo-analysis');
        $evaluator = app(SkillEligibilityEvaluator::class);

        $result = $evaluator->evaluate($skill, []);
        $this->assertFalse($result['eligible']);
        $this->assertTrue($result['abstain']);
        $this->assertSame(SkillEligibilityEvaluator::MISSING_REQUIRED_EVIDENCE, $result['reason_code']);
        $this->assertNotEmpty($result['missing_evidence']);
    }

    public function test_optional_evidence_absence_keeps_skill_eligible(): void
    {
        $skill = app(SkillRegistry::class)->getForModule('website', 'gsc-search-demand-review');
        $evaluator = app(SkillEligibilityEvaluator::class);

        $result = $evaluator->evaluate($skill, ['search_console_performance']);
        $this->assertTrue($result['eligible']);
        $this->assertFalse($result['abstain']);
        $this->assertSame([], $result['missing_evidence']);
    }

    public function test_stale_or_integrity_blocked_evidence_abstains(): void
    {
        $skill = app(SkillRegistry::class)->getForModule('website', 'technical-seo-analysis');
        $evaluator = app(SkillEligibilityEvaluator::class);

        $stale = $evaluator->evaluate($skill, ['page_html'], [], ['page_html' => 'stale']);
        $this->assertFalse($stale['eligible']);
        $this->assertSame(SkillEligibilityEvaluator::REQUIRED_EVIDENCE_STALE, $stale['reason_code']);

        $blocked = $evaluator->evaluate($skill, ['page_html'], [], ['page_html' => 'integrity_blocked']);
        $this->assertFalse($blocked['eligible']);
        $this->assertSame(SkillEligibilityEvaluator::INTEGRITY_BLOCKED, $blocked['reason_code']);
    }

    public function test_provider_semantic_forbidden_claims_present_on_normalized_skills(): void
    {
        $registry = app(SkillRegistry::class);

        $gsc = implode("\n", $registry->getForModule('website', 'gsc-search-demand-review')->effectiveForbiddenClaims());
        $this->assertMatchesRegularExpression('/average position|exact (SERP )?rank/i', $gsc);
        $this->assertMatchesRegularExpression('/impression|search volume|market volume/i', $gsc);

        $ga4 = implode("\n", $registry->getForModule('website', 'ga4-measurement-quality')->effectiveForbiddenClaims());
        $this->assertMatchesRegularExpression('/business outcome|key event/i', $ga4);

        $ads = implode("\n", $registry->getForModule('google-ads', 'measurement-quality-review')->effectiveForbiddenClaims());
        $this->assertMatchesRegularExpression('/qualified lead|conversion/i', $ads);

        $meta = implode("\n", $registry->getForModule('meta-ads', 'measurement-result-review')->effectiveForbiddenClaims());
        $this->assertMatchesRegularExpression('/result|action_type|action type/i', $meta);
    }

    public function test_definition_fingerprint_is_deterministic_and_ignores_presentation_noise(): void
    {
        $a = ['purpose' => 'x', 'version' => '1.0.0', 'slug' => 'a'];
        $b = ['slug' => 'a', 'version' => '1.0.0', 'purpose' => 'x'];
        $this->assertSame(SkillDefinitionFingerprint::hash($a), SkillDefinitionFingerprint::hash($b));

        $skill = app(SkillRegistry::class)->getForModule('website', 'indexability-analysis');
        $this->assertSame(64, strlen($skill->definitionFingerprint()));
        $this->assertSame($skill->definitionFingerprint(), $skill->definitionFingerprint());
    }

    public function test_validator_rejects_unknown_evidence_and_magic_scores(): void
    {
        $dir = $this->tempSkillRoot.'/bad-score';
        File::ensureDirectoryExists($dir);
        File::put($dir.'/SKILL.md', <<<'MD'
---
name: Bad Score
slug: bad-score-skill
version: 1.0.0
module: test-module
purpose: Invent a SEO score of 82/100 for the site.
definition_status: active
required_evidence:
  - key: page_html
    kind: evidence_type
    role: PRIMARY_FACT
    purpose: html
    missing_behavior: ABSTAIN
allowed_conclusions: [FACT_SUMMARY]
forbidden_claims: [x]
abstention_rules: [REQUIRED_EVIDENCE_MISSING]
success_signals: [ok]
reference_sources: [Google]
research_provenance: [test]
---

## When to use
Never.

## Do not use when
Always.

## Methodology
Emit a SEO score of 90/100.

## Output contract
score
MD);

        $loader = app(BuiltInSkillLoader::class);
        $skill = $loader->loadFile('test-module', $this->tempSkillRoot, $dir.'/SKILL.md');
        $errors = app(SkillDefinitionValidator::class)->validate($skill);
        $this->assertContains('magic_score_language', $errors);
    }

    public function test_validator_rejects_business_outcome_purpose(): void
    {
        $dir = $this->tempSkillRoot.'/bad-purpose';
        File::ensureDirectoryExists($dir);
        File::put($dir.'/SKILL.md', <<<'MD'
---
name: Bad Purpose
slug: bad-purpose-skill
version: 1.0.0
module: test-module
purpose: Increase rankings and generate more leads for the brand.
definition_status: active
required_evidence:
  - key: page_html
    kind: evidence_type
    role: PRIMARY_FACT
    purpose: html
    missing_behavior: ABSTAIN
allowed_conclusions: [FACT_SUMMARY]
forbidden_claims: [x]
abstention_rules: [REQUIRED_EVIDENCE_MISSING]
success_signals: [ok]
reference_sources: [Google]
research_provenance: [test]
---

## When to use
Never.

## Do not use when
Always.

## Methodology
Abstain when evidence is missing.

## Output contract
analysis
MD);

        $skill = app(BuiltInSkillLoader::class)->loadFile('test-module', $this->tempSkillRoot, $dir.'/SKILL.md');
        $errors = app(SkillDefinitionValidator::class)->validate($skill);
        $this->assertContains('purpose_promises_business_outcome', $errors);
    }

    public function test_validator_rejects_unknown_evidence_definition(): void
    {
        $dir = $this->tempSkillRoot.'/bad-ev';
        File::ensureDirectoryExists($dir);
        File::put($dir.'/SKILL.md', <<<'MD'
---
name: Bad Ev
slug: bad-evidence-skill
version: 1.0.0
module: test-module
purpose: Analyze evidence safely without promising outcomes.
definition_status: active
required_evidence:
  - key: totally.unknown.definition
    kind: evidence_definition
    role: PRIMARY_FACT
    purpose: bad
    missing_behavior: ABSTAIN
allowed_conclusions: [FACT_SUMMARY]
forbidden_claims: [x]
abstention_rules: [REQUIRED_EVIDENCE_MISSING]
success_signals: [ok]
reference_sources: [Google]
research_provenance: [test]
---

## When to use
Test.

## Do not use when
Never.

## Methodology
Check facts.

## Output contract
analysis
MD);

        $loader = app(BuiltInSkillLoader::class);
        $skill = $loader->loadFile('test-module', $this->tempSkillRoot, $dir.'/SKILL.md');
        $errors = app(SkillDefinitionValidator::class)->validate($skill);
        $this->assertTrue(collect($errors)->contains(fn (string $e): bool => str_starts_with($e, 'evidence_unknown:')));
    }

    public function test_evidence_requirement_cannot_be_both_required_and_optional(): void
    {
        $dir = $this->tempSkillRoot.'/both';
        File::ensureDirectoryExists($dir);
        File::put($dir.'/SKILL.md', <<<'MD'
---
name: Both
slug: both-evidence-skill
version: 1.0.0
module: test-module
purpose: Analyze evidence safely without promising outcomes.
definition_status: active
required_evidence:
  - key: page_html
    kind: evidence_type
    role: PRIMARY_FACT
    purpose: html
    missing_behavior: ABSTAIN
optional_evidence:
  - key: page_html
    kind: evidence_type
    role: OPTIONAL_ENRICHMENT
    purpose: also
    missing_behavior: CONTINUE
allowed_conclusions: [FACT_SUMMARY]
forbidden_claims: [x]
abstention_rules: [REQUIRED_EVIDENCE_MISSING]
success_signals: [ok]
reference_sources: [Google]
research_provenance: [test]
---

## When to use
Test.

## Do not use when
Never.

## Methodology
Check.

## Output contract
analysis
MD);

        $skill = app(BuiltInSkillLoader::class)->loadFile('test-module', $this->tempSkillRoot, $dir.'/SKILL.md');
        $errors = app(SkillDefinitionValidator::class)->validate($skill);
        $this->assertContains('evidence_both_required_and_optional:page_html', $errors);
    }

    public function test_skill_definition_does_not_create_domain_objects(): void
    {
        $this->assertSame(0, Finding::query()->count());
        $this->assertSame(0, Task::query()->count());

        app(SkillRegistry::class)->all();
        app(SkillDefinitionValidator::class);

        $this->assertSame(0, Finding::query()->count());
        $this->assertSame(0, Task::query()->count());
    }

    public function test_structured_evidence_requirement_parses_from_yaml(): void
    {
        $skill = app(SkillRegistry::class)->getForModule('website', 'metadata-consistency');
        $this->assertNotEmpty($skill->requiredEvidenceRequirements);
        $this->assertInstanceOf(SkillEvidenceRequirement::class, $skill->requiredEvidenceRequirements[0]);
        $this->assertSame(SkillEvidenceRequirement::MISSING_ABSTAIN, $skill->requiredEvidenceRequirements[0]->missingBehavior);
    }

    public function test_external_repo_branding_absent_from_stable_keys(): void
    {
        foreach (app(SkillRegistry::class)->all() as $skill) {
            $this->assertDoesNotMatchRegularExpression('/claude|open-seo|marketing-skills|platinum|seranking/i', $skill->stableKey());
            $this->assertDoesNotMatchRegularExpression('/claude|open-seo|platinum/i', $skill->slug);
        }
    }
}
