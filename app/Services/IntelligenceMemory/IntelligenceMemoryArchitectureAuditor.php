<?php

namespace App\Services\IntelligenceMemory;

use Illuminate\Support\Facades\Schema;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RegexIterator;

/**
 * Architectural auditor for Prompt 51 invariants (tests + ops checks).
 */
final class IntelligenceMemoryArchitectureAuditor
{
    /**
     * @return list<string>
     */
    public function forbiddenGenericMemoryTables(): array
    {
        $candidates = [
            'memories',
            'memory_embeddings',
            'memory_entries',
            'memory_records',
            'agent_memories',
            'ai_memories',
            'shared_memories',
            'knowledge_memories',
        ];

        $found = [];
        foreach ($candidates as $table) {
            if (Schema::hasTable($table)) {
                $found[] = $table;
            }
        }

        return $found;
    }

    /**
     * @return list<string>
     */
    public function forbiddenVectorDependencySignals(): array
    {
        $signals = [];
        $composerLock = base_path('composer.lock');
        if (is_file($composerLock)) {
            $json = (string) file_get_contents($composerLock);
            foreach (['pgvector', 'pinecone', 'weaviate', 'qdrant', 'milvus'] as $needle) {
                if (stripos($json, $needle) !== false) {
                    $signals[] = "composer.lock:{$needle}";
                }
            }
        }

        return $signals;
    }

    /**
     * @return list<string>
     */
    public function forbiddenAgentMemoryToolClasses(): array
    {
        $roots = [
            base_path('app/Services/Ai'),
            base_path('app/Support/Ai'),
            base_path('app/Services/IntelligenceMemory'),
            base_path('app/Support/IntelligenceMemory'),
        ];

        $found = [];
        foreach ($roots as $root) {
            if (! is_dir($root)) {
                continue;
            }
            foreach ($this->phpFiles($root) as $file) {
                $base = basename($file);
                if (preg_match('/^(SearchAllMemory|MemorySearchTool|GenericMemoryTool|AgentMemoryTool)\\.php$/i', $base) === 1) {
                    $found[] = $file;
                }
            }
        }

        return $found;
    }

    /**
     * @return list<string>
     */
    public function primaryMemoryLayers(): array
    {
        return ['brand', 'sector', 'skill'];
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
