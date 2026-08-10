<?php

namespace Tests\Feature;

use App\Models\Brand;
use App\Models\BrandIntelligenceContext;
use App\Models\CoreIntegration;
use App\Models\DigitalAsset;
use App\Models\Evidence;
use App\Models\Finding;
use App\Models\Recommendation;
use App\Models\Run;
use App\Models\Task;
use App\Models\User;
use App\Services\Integrations\OpenAi\OpenAiProviderCredentialService;
use App\Services\WebsiteAiInsightService;
use App\Support\Roles;
use Database\Seeders\RoleAndPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Laravel\Ai\Prompts\AgentPrompt;
use MoxDop\Website\Ai\Agents\WebsiteRecommendationAgent;
use MoxDop\Website\Ai\WebsiteAiRecommendationAcceptance;
use MoxDop\Website\Ai\WebsiteAiRecommendationConfig;
use MoxDop\Website\Ai\WebsiteAiRecommendationService;
use RuntimeException;
use Tests\TestCase;

class WebsiteAiRecommendationIntelligenceTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

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
            'moxdop.openai.recommendation_model' => 'gpt-5-mini',
        ]);

        $integration = CoreIntegration::factory()->openai()->create([
            'status' => CoreIntegration::STATUS_ACTIVE,
        ]);
        app(OpenAiProviderCredentialService::class)->save($integration, [
            'api_key' => 'sk-test-ai',
        ], $this->admin);
    }

    public function test_successful_analysis_includes_brand_findings_evidence_and_deterministic_recommendation(): void
    {
        [$asset, $finding, $evidence, $deterministic] = $this->seedContext();

        WebsiteRecommendationAgent::fake([
            $this->fakeStructured($finding->id, $evidence->id),
        ])->preventStrayPrompts();

        $result = app(WebsiteAiRecommendationService::class)->analyze($asset);
        $run = $result['run'];

        $this->assertFalse($result['reused']);
        $this->assertSame('completed', $run->status);
        $this->assertSame(WebsiteAiRecommendationConfig::MODULE_ID, $run->module_id);
        $this->assertSame('gpt-5-mini', $run->metadata['model']);
        $this->assertSame(WebsiteAiRecommendationConfig::PROMPT_VERSION, $run->metadata['prompt_version']);
        $this->assertFalse($run->metadata['openai_store']);
        $this->assertArrayNotHasKey('api_key', $run->metadata);
        $this->assertStringNotContainsString('sk-test-ai', json_encode($run->metadata));

        $insight = Evidence::query()->where('run_id', $run->id)->first();
        $this->assertTrue($insight->payload['ok']);
        $this->assertTrue($insight->payload['derived']);
        $this->assertTrue($insight->payload['generated_by_ai']);
        $this->assertSame([$finding->id], $insight->payload['finding_ids']);
        $this->assertSame([$evidence->id], $insight->payload['evidence_ids']);
        $this->assertSame(0, Recommendation::query()->where('source_module', WebsiteAiRecommendationConfig::MODULE_ID)->count());
        $this->assertSame(0, Task::query()->count());
        $this->assertSame(1, Finding::query()->count());
        $this->assertSame('open', $finding->fresh()->status);
        $this->assertSame($deterministic->title, Recommendation::query()->find($deterministic->id)?->title);

        WebsiteRecommendationAgent::assertPrompted(function (AgentPrompt $prompt) use ($finding, $evidence, $deterministic): bool {
            return $prompt->contains('CONTEXT_JSON:')
                && $prompt->contains('important_constraints')
                && $prompt->contains('Patient before/after advertising cannot be used')
                && $prompt->contains('"id": '.$finding->id)
                && $prompt->contains('"id": '.$evidence->id)
                && $prompt->contains($deterministic->title)
                && ! $prompt->contains('sk-test-ai')
                && ! $prompt->contains(str_repeat('X', 500))
                && ! $prompt->contains('secret-token-value');
        });
    }

    public function test_unknown_finding_id_is_rejected_and_leaves_product_state_unchanged(): void
    {
        [$asset, $finding, $evidence, $deterministic] = $this->seedContext();
        $beforeFindings = Finding::query()->count();
        $beforeRecs = Recommendation::query()->count();

        WebsiteRecommendationAgent::fake([
            [
                'executive_summary' => 'Bad grounding',
                'overall_priority' => 'high',
                'context_observations' => [],
                'finding_interpretations' => [
                    [
                        'finding_id' => 999999,
                        'evidence_ids' => [$evidence->id],
                        'explanation' => 'Invented',
                        'business_relevance' => 'Invented',
                        'likely_contributors' => ['none'],
                        'uncertainty' => 'high',
                        'suggested_priority' => 'high',
                        'recommendation_draft' => [
                            'title' => 'Bad',
                            'action' => 'Bad',
                            'rationale' => 'Bad',
                            'effort' => 'low',
                        ],
                        'dependencies' => [],
                        'success_signal' => 'n/a',
                        'failure_signal' => 'n/a',
                        'watch_metrics' => [],
                    ],
                ],
                'prompt_version' => WebsiteAiRecommendationConfig::PROMPT_VERSION,
            ],
        ])->preventStrayPrompts();

        $result = app(WebsiteAiRecommendationService::class)->analyze($asset);

        $this->assertSame('failed', $result['run']->status);
        $this->assertSame($beforeFindings, Finding::query()->count());
        $this->assertSame($beforeRecs, Recommendation::query()->count());
        $this->assertSame($deterministic->title, $deterministic->fresh()->title);
        $this->assertSame('open', $finding->fresh()->status);
        $this->assertSame(0, Task::query()->count());
    }

    public function test_unknown_evidence_id_is_rejected(): void
    {
        [$asset, $finding] = $this->seedContext();

        WebsiteRecommendationAgent::fake([
            [
                'executive_summary' => 'Bad evidence grounding',
                'overall_priority' => 'medium',
                'context_observations' => [],
                'finding_interpretations' => [
                    [
                        'finding_id' => $finding->id,
                        'evidence_ids' => [999999],
                        'explanation' => 'Uses unknown evidence',
                        'business_relevance' => 'Should fail',
                        'likely_contributors' => [],
                        'uncertainty' => 'high',
                        'suggested_priority' => 'medium',
                        'recommendation_draft' => [
                            'title' => 'Draft',
                            'action' => 'Act',
                            'rationale' => 'Why',
                            'effort' => 'medium',
                        ],
                        'dependencies' => [],
                        'success_signal' => 'ok',
                        'failure_signal' => 'bad',
                        'watch_metrics' => [],
                    ],
                ],
                'prompt_version' => WebsiteAiRecommendationConfig::PROMPT_VERSION,
            ],
        ])->preventStrayPrompts();

        $result = app(WebsiteAiRecommendationService::class)->analyze($asset);
        $this->assertSame('failed', $result['run']->status);
        $this->assertSame('InvalidArgumentException', $result['run']->metadata['error_class']);
    }

    public function test_previous_ai_insight_excluded_from_source_evidence(): void
    {
        [$asset, $finding, $evidence] = $this->seedContext();

        Evidence::factory()->create([
            'run_id' => $finding->last_run_id,
            'digital_asset_id' => $asset->id,
            'source_module' => WebsiteAiRecommendationConfig::MODULE_ID,
            'type' => WebsiteAiRecommendationConfig::EVIDENCE_TYPE_AI_INSIGHT,
            'title' => 'Prior AI',
            'payload' => [
                'ok' => true,
                'derived' => true,
                'generated_by_ai' => true,
                'summary' => 'Prior derived insight must not re-enter grounding',
            ],
        ]);

        WebsiteRecommendationAgent::fake([
            $this->fakeStructured($finding->id, $evidence->id),
        ])->preventStrayPrompts();

        app(WebsiteAiRecommendationService::class)->analyze($asset);

        WebsiteRecommendationAgent::assertPrompted(function (AgentPrompt $prompt) use ($evidence): bool {
            return $prompt->contains('"id": '.$evidence->id)
                && ! $prompt->contains('Prior derived insight must not re-enter grounding');
        });
    }

    public function test_fingerprint_reuses_successful_insight_without_second_model_call(): void
    {
        [$asset, $finding, $evidence] = $this->seedContext();

        WebsiteRecommendationAgent::fake([
            $this->fakeStructured($finding->id, $evidence->id),
            $this->fakeStructured($finding->id, $evidence->id),
        ])->preventStrayPrompts();

        $first = app(WebsiteAiRecommendationService::class)->analyze($asset);
        $this->assertFalse($first['reused']);

        $second = app(WebsiteAiRecommendationService::class)->analyze($asset);
        $this->assertTrue($second['reused']);
        $this->assertSame($first['insight']->id, $second['insight']->id);
        $this->assertStringContainsString('already current', $second['message']);

        WebsiteRecommendationAgent::assertPrompted(function (): bool {
            return true;
        });

        // Only one model prompt for identical context.
        $this->assertSame(1, Run::query()->where('module_id', WebsiteAiRecommendationConfig::MODULE_ID)->where('status', 'completed')->count());
    }

    public function test_changed_brand_context_invalidates_fingerprint(): void
    {
        [$asset, $finding, $evidence] = $this->seedContext();

        WebsiteRecommendationAgent::fake([
            $this->fakeStructured($finding->id, $evidence->id),
            $this->fakeStructured($finding->id, $evidence->id),
        ])->preventStrayPrompts();

        app(WebsiteAiRecommendationService::class)->analyze($asset);

        BrandIntelligenceContext::query()->where('brand_id', $asset->brand_id)->update([
            'business_summary' => 'Updated clinic positioning for fingerprint change.',
        ]);

        $second = app(WebsiteAiRecommendationService::class)->analyze($asset->fresh());
        $this->assertFalse($second['reused']);
        $this->assertSame(2, Run::query()->where('module_id', WebsiteAiRecommendationConfig::MODULE_ID)->where('status', 'completed')->count());
    }

    public function test_acceptance_creates_ai_recommendation_without_task_or_finding_mutation(): void
    {
        [$asset, $finding, $evidence, $deterministic] = $this->seedContext();

        WebsiteRecommendationAgent::fake([
            $this->fakeStructured($finding->id, $evidence->id),
        ])->preventStrayPrompts();

        app(WebsiteAiRecommendationService::class)->analyze($asset);

        $result = app(WebsiteAiRecommendationAcceptance::class)->acceptDraft($asset, $finding->id);

        $this->assertTrue($result['created']);
        $this->assertSame(WebsiteAiRecommendationConfig::MODULE_ID, $result['recommendation']->source_module);
        $this->assertSame('Publish a valid XML sitemap', $result['recommendation']->title);
        $this->assertSame(0, Task::query()->count());
        $this->assertSame('open', $finding->fresh()->status);
        $this->assertSame($deterministic->id, Recommendation::query()->where('source_module', 'website-diagnosis')->value('id'));
        $this->assertSame(2, Recommendation::query()->count());
    }

    public function test_acceptance_preserves_terminal_ai_recommendation(): void
    {
        [$asset, $finding, $evidence] = $this->seedContext();

        WebsiteRecommendationAgent::fake([
            $this->fakeStructured($finding->id, $evidence->id),
        ])->preventStrayPrompts();
        app(WebsiteAiRecommendationService::class)->analyze($asset);

        $terminal = Recommendation::query()->create([
            'finding_id' => $finding->id,
            'digital_asset_id' => $asset->id,
            'source_module' => WebsiteAiRecommendationConfig::MODULE_ID,
            'title' => 'Old AI draft',
            'action' => 'Old action',
            'rationale' => 'Old',
            'priority' => 'low',
            'effort' => 'low',
            'status' => 'dismissed',
        ]);

        $result = app(WebsiteAiRecommendationAcceptance::class)->acceptDraft($asset, $finding->id);

        $this->assertFalse($result['updated']);
        $this->assertFalse($result['created']);
        $this->assertSame('Old AI draft', $terminal->fresh()->title);
        $this->assertSame('dismissed', $terminal->fresh()->status);
    }

    public function test_failed_ai_preserves_previous_successful_insight(): void
    {
        [$asset, $finding, $evidence] = $this->seedContext();

        WebsiteRecommendationAgent::fake([
            $this->fakeStructured($finding->id, $evidence->id),
            function (): never {
                throw new RuntimeException('provider unavailable');
            },
        ])->preventStrayPrompts();

        $first = app(WebsiteAiRecommendationService::class)->analyze($asset);
        $this->assertSame('completed', $first['run']->status);

        // Force fingerprint change so a second call is attempted.
        BrandIntelligenceContext::query()->where('brand_id', $asset->brand_id)->update([
            'important_constraints' => 'Updated constraint for failure path.',
        ]);

        $second = app(WebsiteAiRecommendationService::class)->analyze($asset->fresh());
        $this->assertSame('failed', $second['run']->status);
        $this->assertNotNull($second['insight']);
        $this->assertSame($first['insight']->id, $second['insight']->id);
        $this->assertSame(0, Task::query()->count());
        $this->assertSame(1, Finding::query()->count());
    }

    public function test_core_facade_still_works(): void
    {
        [$asset, $finding, $evidence] = $this->seedContext();

        WebsiteRecommendationAgent::fake([
            $this->fakeStructured($finding->id, $evidence->id),
        ])->preventStrayPrompts();

        $run = app(WebsiteAiInsightService::class)->interpret($asset);
        $this->assertSame('completed', $run->status);
    }

    public function test_missing_brand_fields_remain_absent_in_prompt(): void
    {
        $brand = Brand::factory()->create();
        BrandIntelligenceContext::factory()->create([
            'brand_id' => $brand->id,
            'business_summary' => 'Clinic summary only',
            'business_model' => null,
            'products_services' => [],
            'priority_offerings' => [],
            'target_audiences' => [],
            'target_markets' => [],
            'business_goals' => [],
            'conversion_goals' => [],
            'positioning' => null,
            'differentiators' => [],
            'known_competitors' => [],
            'important_constraints' => null,
        ]);

        $asset = DigitalAsset::factory()->create([
            'brand_id' => $brand->id,
            'type' => 'website',
            'primary_url' => 'https://example.com',
        ]);
        [$finding, $evidence] = $this->seedFindingEvidence($asset);

        WebsiteRecommendationAgent::fake([
            $this->fakeStructured($finding->id, $evidence->id),
        ])->preventStrayPrompts();

        app(WebsiteAiRecommendationService::class)->analyze($asset);

        WebsiteRecommendationAgent::assertPrompted(function (AgentPrompt $prompt): bool {
            return $prompt->contains('Clinic summary only')
                && ! $prompt->contains('"business_model": null')
                && ! $prompt->contains('"important_constraints": null')
                && ! $prompt->contains('"positioning": null');
        });
    }

    public function test_rejects_when_openai_not_configured(): void
    {
        CoreIntegration::query()->where('provider', 'openai')->delete();
        config([
            'moxdop.openai.api_key' => null,
            'ai.providers.openai.key' => null,
        ]);

        [$asset] = $this->seedContext();

        WebsiteRecommendationAgent::fake([])->preventStrayPrompts();

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('No eligible AI providers');

        app(WebsiteAiRecommendationService::class)->analyze($asset);
    }

    /**
     * @return array{0: DigitalAsset, 1: Finding, 2: Evidence, 3: Recommendation}
     */
    private function seedContext(): array
    {
        $brand = Brand::factory()->create(['name' => 'Demo Clinic']);
        BrandIntelligenceContext::factory()->create([
            'brand_id' => $brand->id,
            'business_summary' => 'Aesthetic clinic focused on non-surgical treatments.',
            'business_model' => 'healthcare_clinic',
            'important_constraints' => 'Patient before/after advertising cannot be used',
            'priority_offerings' => ['Hydrafacial'],
            'products_services' => [['name' => 'Hydrafacial', 'description' => 'Skin treatment']],
            'target_audiences' => [['name' => 'Adults 25-45', 'note' => null]],
            'target_markets' => [['name' => 'Istanbul', 'note' => null]],
            'business_goals' => [['goal' => 'Increase qualified consult bookings', 'note' => null]],
            'conversion_goals' => [['type' => 'form_submission', 'label' => 'Consult form', 'note' => null]],
            'positioning' => 'Premium local aesthetic care',
            'differentiators' => ['Doctor-led protocols'],
            'known_competitors' => [['name' => 'Rival Clinic', 'url' => null, 'note' => null]],
        ]);

        $asset = DigitalAsset::factory()->create([
            'brand_id' => $brand->id,
            'type' => 'website',
            'primary_url' => 'https://example.com',
            'domain' => 'example.com',
        ]);

        [$finding, $evidence] = $this->seedFindingEvidence($asset);

        $deterministic = Recommendation::factory()->create([
            'finding_id' => $finding->id,
            'digital_asset_id' => $asset->id,
            'source_module' => 'website-diagnosis',
            'title' => 'Add sitemap.xml',
            'action' => 'Publish a valid sitemap at /sitemap.xml',
            'rationale' => 'Deterministic diagnosis baseline',
            'priority' => 'medium',
            'effort' => 'low',
            'status' => 'open',
        ]);

        return [$asset, $finding, $evidence, $deterministic];
    }

    /**
     * @return array{0: Finding, 1: Evidence}
     */
    private function seedFindingEvidence(DigitalAsset $asset): array
    {
        $diagnosisRun = Run::factory()->create([
            'digital_asset_id' => $asset->id,
            'module_id' => 'website-diagnosis',
            'status' => 'completed',
        ]);

        $evidence = Evidence::factory()->sitemap()->create([
            'run_id' => $diagnosisRun->id,
            'digital_asset_id' => $asset->id,
            'source_module' => 'website-diagnosis',
            'payload' => [
                'host' => 'example.com',
                'tried_urls' => ['https://example.com/sitemap.xml'],
                'present' => false,
                'status_code' => 404,
                'body' => str_repeat('X', 1200),
                'html' => '<html>secret-ish</html>',
                'authorization' => 'secret-token-value',
                'api_key' => 'should-not-appear',
                'password' => 'should-not-appear',
                'reason_code' => 'not_found',
            ],
        ]);

        $finding = Finding::factory()->create([
            'digital_asset_id' => $asset->id,
            'source_module' => 'website-diagnosis',
            'fingerprint' => 'sitemap-xml-availability|host=example.com',
            'category' => 'indexability',
            'severity' => 'high',
            'title' => 'Sitemap missing or unreadable',
            'summary' => 'No readable sitemap.xml was found for example.com.',
            'confidence' => 0.7,
            'status' => 'open',
            'last_run_id' => $diagnosisRun->id,
        ]);

        return [$finding, $evidence];
    }

    /**
     * @return array<string, mixed>
     */
    private function fakeStructured(int $findingId, int $evidenceId): array
    {
        return [
            'executive_summary' => 'Organic discovery is limited by a missing sitemap.',
            'overall_priority' => 'high',
            'context_observations' => [
                'Brand constraint forbids before/after advertising.',
            ],
            'finding_interpretations' => [
                [
                    'finding_id' => $findingId,
                    'evidence_ids' => [$evidenceId],
                    'explanation' => 'Sitemap endpoint returned 404 while impressions context is unavailable.',
                    'business_relevance' => 'Slower organic discovery may reduce consult demand.',
                    'likely_contributors' => ['Missing sitemap.xml response'],
                    'uncertainty' => 'medium',
                    'suggested_priority' => 'high',
                    'recommendation_draft' => [
                        'title' => 'Publish a valid XML sitemap',
                        'action' => 'Publish https://example.com/sitemap.xml as a well-formed urlset.',
                        'rationale' => 'Evidence shows sitemap availability failed; respect advertising constraints.',
                        'effort' => 'low',
                    ],
                    'dependencies' => ['CMS publish access'],
                    'success_signal' => 'Sitemap returns 200 with urlset',
                    'failure_signal' => 'Sitemap still 404 after publish',
                    'watch_metrics' => ['GSC coverage', 'indexed pages'],
                ],
            ],
            'prompt_version' => WebsiteAiRecommendationConfig::PROMPT_VERSION,
        ];
    }
}
