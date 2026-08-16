<?php

namespace Tests\Unit;

use App\Enums\IntelligenceMemoryLayer;
use App\Services\IntelligenceMemory\IntelligenceMemoryArchitectureAuditor;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RegexIterator;
use Tests\TestCase;

/**
 * Prompt 51 — static architecture audit for memory primitives / unsafe brain assumptions.
 */
class IntelligenceMemoryPrimitiveAuditTest extends TestCase
{
    public function test_no_generic_memories_migration_exists(): void
    {
        $migrations = glob(base_path('database/migrations/*.php')) ?: [];
        $hits = [];
        foreach ($migrations as $file) {
            $base = basename($file);
            if (preg_match('/create_(memories|memory_embeddings|agent_memories|ai_memories|shared_memories)_table/i', $base) === 1) {
                $hits[] = $base;
            }
            $contents = (string) file_get_contents($file);
            if (preg_match("/Schema::create\\('(memories|memory_embeddings|agent_memories)'\\s*,/", $contents) === 1) {
                $hits[] = $base.':schema';
            }
        }
        $this->assertSame([], $hits);
    }

    public function test_no_memory_v2_or_brain_service_classes(): void
    {
        $forbidden = [];
        foreach ($this->phpFiles(base_path('app')) as $file) {
            $base = basename($file);
            if (preg_match('/^(MemoryV2|MoxdopBrainService|SharedMemoryStore|GlobalCustomerMemory)\\.php$/i', $base) === 1) {
                $forbidden[] = $file;
            }
        }
        $this->assertSame([], $forbidden);
    }

    public function test_agent_execution_services_do_not_reference_memory_pack_injection(): void
    {
        $violations = [];
        foreach (['app/Services/Ai', 'app/Support/Ai'] as $rel) {
            $root = base_path($rel);
            if (! is_dir($root)) {
                continue;
            }
            foreach ($this->phpFiles($root) as $file) {
                $contents = (string) file_get_contents($file);
                if (str_contains($contents, 'MemoryContextPack')
                    || str_contains($contents, 'search_all_memory')
                    || str_contains($contents, 'IntelligenceMemoryGateway')) {
                    $violations[] = $file;
                }
            }
        }
        $this->assertSame([], $violations, "Prompt 50 Agent path must not inject Memory yet.\n".implode("\n", $violations));
    }

    public function test_three_layers_have_distinct_privacy_classes(): void
    {
        $classes = [];
        foreach (IntelligenceMemoryLayer::cases() as $layer) {
            $classes[$layer->value] = $layer->privacyClass();
        }
        $this->assertSame('tenant_confidential', $classes['brand']);
        $this->assertSame('privacy_qualified_aggregate', $classes['sector']);
        $this->assertSame('general_non_customer', $classes['skill']);
        $this->assertCount(3, array_unique($classes));
    }

    public function test_auditor_reports_no_forbidden_tools(): void
    {
        $this->assertSame([], app(IntelligenceMemoryArchitectureAuditor::class)->forbiddenAgentMemoryToolClasses());
    }

    /**
     * @return \Generator<int, string>
     */
    private function phpFiles(string $root): \Generator
    {
        $iterator = new RegexIterator(
            new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root)),
            '/^.+\.php$/i',
            RegexIterator::GET_MATCH,
        );
        foreach ($iterator as $match) {
            yield $match[0];
        }
    }
}
