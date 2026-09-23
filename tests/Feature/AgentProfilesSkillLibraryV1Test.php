<?php

namespace Tests\Feature;

use App\Models\CoreIntegration;
use App\Models\User;
use App\Services\Integrations\OpenAi\OpenAiProviderCredentialService;
use App\Support\Roles;
use App\Support\Skills\BuiltInSkillLoader;
use App\Support\Skills\SkillRegistry;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use InvalidArgumentException;
use RuntimeException;
use Tests\TestCase;

class AgentProfilesSkillLibraryV1Test extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private string $tempSkillRoot;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RoleAndPermissionSeeder::class);
        $this->admin = User::factory()->create();
        $this->admin->assignRole(Roles::ADMIN);

        config([
            'moxdop.openai.api_key' => null,
            'ai.providers.openai.key' => null,
            'ai.providers.openai.store' => false,
        ]);

        $integration = CoreIntegration::factory()->openai()->create([
            'status' => CoreIntegration::STATUS_ACTIVE,
        ]);
        app(OpenAiProviderCredentialService::class)->save($integration, [
            'api_key' => 'sk-test-agent-skill',
        ], $this->admin);

        $this->tempSkillRoot = storage_path('framework/testing/skills-'.uniqid());
        File::ensureDirectoryExists($this->tempSkillRoot);
    }

    protected function tearDown(): void
    {
        if (isset($this->tempSkillRoot) && is_dir($this->tempSkillRoot)) {
            File::deleteDirectory($this->tempSkillRoot);
        }

        parent::tearDown();
    }

    public function test_built_in_website_skills_are_discovered_and_validated(): void
    {
        $skills = app(SkillRegistry::class)->forModule('website');
        $slugs = collect($skills)->pluck('slug')->all();

        $this->assertEqualsCanonicalizing([
            'brand-context-discovery',
        ], $slugs);

        foreach ($skills as $skill) {
            $this->assertSame('website', $skill->module);
            $this->assertMatchesRegularExpression('/^\d+\.\d+\.\d+$/', $skill->version);
            $this->assertNotSame('', $skill->purpose);
            $this->assertDoesNotMatchRegularExpression('/<\?php|eval\s*\(/i', $skill->bodyMarkdown);
        }
    }

    public function test_duplicate_skill_slug_is_rejected_within_the_same_module(): void
    {
        $dir = $this->tempSkillRoot.'/dup';
        File::ensureDirectoryExists($dir);
        File::put($dir.'/SKILL.md', $this->minimalSkillMarkdown('technical-seo-analysis', 'test-module'));

        $dirTwo = $this->tempSkillRoot.'/dup-two';
        File::ensureDirectoryExists($dirTwo);
        File::put($dirTwo.'/SKILL.md', $this->minimalSkillMarkdown('technical-seo-analysis', 'test-module'));

        $registry = new SkillRegistry(app(BuiltInSkillLoader::class));
        $registry->registerRoot('test-module', $this->tempSkillRoot);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Duplicate Skill slug');
        $registry->all();
    }

    public function test_malformed_skill_fails_loudly(): void
    {
        $dir = $this->tempSkillRoot.'/bad';
        File::ensureDirectoryExists($dir);
        File::put($dir.'/SKILL.md', "---\nname: Bad\n---\n## Methodology\nNo slug.\n");

        $loader = app(BuiltInSkillLoader::class);

        $this->expectException(InvalidArgumentException::class);
        $loader->loadFromRoot([
            'module' => 'test-module',
            'absolute_root' => $this->tempSkillRoot,
        ]);
    }

    public function test_path_traversal_and_executable_payloads_are_rejected(): void
    {
        $dir = $this->tempSkillRoot.'/evil';
        File::ensureDirectoryExists($dir);
        File::put($dir.'/SKILL.md', $this->minimalSkillMarkdown('evil-skill', 'test-module', "<?php echo 'no';"));

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('executable code');
        app(BuiltInSkillLoader::class)->loadFromRoot([
            'module' => 'test-module',
            'absolute_root' => $this->tempSkillRoot,
        ]);
    }

    public function test_remote_skill_loading_is_impossible_via_registry_roots_only(): void
    {
        $registry = app(SkillRegistry::class);
        foreach ($registry->roots() as $root) {
            $this->assertDirectoryExists($root['absolute_root']);
            $this->assertStringNotContainsString('http', $root['absolute_root']);
            $this->assertTrue(
                str_starts_with($root['absolute_root'], base_path('app-modules'))
                || str_starts_with($root['absolute_root'], base_path('resources/skills'))
                || $root['absolute_root'] === base_path('resources/search-demand-skills'),
                $root['absolute_root'],
            );
        }
    }

    private function minimalSkillMarkdown(string $slug, string $module, string $extraBody = ''): string
    {
        return <<<MD
---
name: Temp Skill
slug: {$slug}
version: 1.0.0
module: {$module}
purpose: Temporary test skill
required_evidence: []
required_capabilities: []
---

## When to use

Testing.

## Do not use when

Never in production.

## Methodology

Do nothing harmful.

## Rules

Stay safe.

## Allowed conclusions

- None

## Forbidden claims

- Everything unsafe

## Output contract

N/A

## Success signals

- Pass

## Failure signals

- Fail

## Watch metrics

- None

{$extraBody}
MD;
    }
}
