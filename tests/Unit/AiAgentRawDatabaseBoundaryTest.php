<?php

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RegexIterator;
use Tests\TestCase;

/**
 * Prompt 50 — Agent execution core must not use raw DB / SQL tools.
 *
 * Persistence is allowed only in AgentContextGateway + AgentExecutionRecorder.
 * AiRouteResolver retains pre-existing control-plane route persistence (excluded).
 */
class AiAgentRawDatabaseBoundaryTest extends TestCase
{
    /**
     * Files under app/Services/Ai that may touch persistence / DB facades.
     *
     * @var list<string>
     */
    private const PERSISTENCE_ALLOWLIST = [
        'AgentContextGateway.php',
        'AgentExecutionRecorder.php',
        'AiRouteResolver.php',
        'AiProviderRuntimeConfig.php',
    ];

    /**
     * @var list<string>
     */
    private const FORBIDDEN_BUSINESS_QUERY_MODELS = [
        'Customer',
        'Brand',
        'Finding',
        'Task',
        'Recommendation',
        'Opportunity',
    ];

    #[Test]
    public function agent_execution_core_must_not_use_raw_db_facades(): void
    {
        $violations = [];

        foreach ($this->phpFiles(base_path('app/Support/Ai')) as $file) {
            $violations = array_merge($violations, $this->scanForDbFacades($file));
        }

        foreach ($this->phpFiles(base_path('app/Services/Ai')) as $file) {
            if (in_array(basename($file), self::PERSISTENCE_ALLOWLIST, true)) {
                continue;
            }
            $violations = array_merge($violations, $this->scanForDbFacades($file));
        }

        $this->assertSame([], $violations, "Forbidden DB facade usage:\n".implode("\n", $violations));
    }

    #[Test]
    public function agent_execution_core_must_not_query_business_models_directly(): void
    {
        $violations = [];

        foreach ($this->phpFiles(base_path('app/Support/Ai')) as $file) {
            $violations = array_merge($violations, $this->scanForBusinessQueries($file));
        }

        foreach ($this->phpFiles(base_path('app/Services/Ai')) as $file) {
            if (in_array(basename($file), ['AgentContextGateway.php', 'AgentExecutionRecorder.php'], true)) {
                continue;
            }
            $violations = array_merge($violations, $this->scanForBusinessQueries($file));
        }

        $this->assertSame([], $violations, "Forbidden business model queries:\n".implode("\n", $violations));
    }

    #[Test]
    public function generic_database_or_sql_tool_classes_must_not_exist(): void
    {
        $roots = [
            base_path('app/Services/Ai'),
            base_path('app/Support/Ai'),
            base_path('app/Ai'),
        ];

        $found = [];
        foreach ($roots as $root) {
            if (! is_dir($root)) {
                continue;
            }
            foreach ($this->phpFiles($root) as $file) {
                $base = basename($file);
                if (preg_match('/^(DatabaseTool|SqlTool|RawDbTool|GenericDatabaseTool)\\.php$/i', $base) === 1) {
                    $found[] = $file;
                }
            }
        }

        $this->assertSame([], $found, 'Generic DatabaseTool / SqlTool classes must not exist.');
    }

    /**
     * @return list<string>
     */
    private function scanForDbFacades(string $file): array
    {
        $contents = (string) file_get_contents($file);
        $hits = [];

        if (str_contains($contents, 'Illuminate\\Support\\Facades\\DB')) {
            $hits[] = $file.': imports Illuminate\\Support\\Facades\\DB';
        }
        if (preg_match('/\\bDB::/', $contents) === 1) {
            $hits[] = $file.': uses DB::';
        }

        return $hits;
    }

    /**
     * @return list<string>
     */
    private function scanForBusinessQueries(string $file): array
    {
        $contents = (string) file_get_contents($file);
        $hits = [];

        foreach (self::FORBIDDEN_BUSINESS_QUERY_MODELS as $model) {
            if (preg_match('/\\b'.$model.'::query\\s*\\(/', $contents) === 1) {
                $hits[] = $file.': '.$model.'::query()';
            }
        }

        return $hits;
    }

    /**
     * @return list<string>
     */
    private function phpFiles(string $directory): array
    {
        if (! is_dir($directory)) {
            return [];
        }

        $iterator = new RegexIterator(
            new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory)),
            '/^.+\\.php$/i',
            RegexIterator::GET_MATCH
        );

        $files = [];
        foreach ($iterator as $match) {
            $files[] = $match[0];
        }

        sort($files);

        return $files;
    }
}
