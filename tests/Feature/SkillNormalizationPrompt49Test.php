<?php

namespace Tests\Feature;

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

    public function test_external_repo_branding_absent_from_stable_keys(): void
    {
        foreach (app(SkillRegistry::class)->all() as $skill) {
            $this->assertDoesNotMatchRegularExpression('/claude|open-seo|marketing-skills|platinum|seranking/i', $skill->stableKey());
            $this->assertDoesNotMatchRegularExpression('/claude|open-seo|platinum/i', $skill->slug);
        }
    }
}
