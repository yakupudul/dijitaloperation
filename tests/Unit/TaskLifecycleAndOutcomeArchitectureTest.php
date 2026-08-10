<?php

namespace Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RegexIterator;
use Tests\TestCase;

/**
 * Architecture regression: Outcome Loop V1 must not introduce Result/Outcome entities or tables.
 */
class TaskLifecycleAndOutcomeArchitectureTest extends TestCase
{
    #[Test]
    public function no_result_or_outcome_entity_or_table_is_introduced(): void
    {
        $forbiddenModelFiles = [
            base_path('app/Models/Result.php'),
            base_path('app/Models/Outcome.php'),
            base_path('app/Models/TaskOutcome.php'),
            base_path('app/Models/TaskResult.php'),
            base_path('app/Repositories/ResultRepository.php'),
        ];

        foreach ($forbiddenModelFiles as $path) {
            $this->assertFileDoesNotExist($path, "Forbidden model/file must not exist: {$path}");
        }

        $forbiddenMigrationPatterns = [
            '/create_results_table/',
            '/create_outcomes_table/',
            '/create_task_outcomes_table/',
            '/create_task_results_table/',
            '/create_task_outcome_events_table/',
        ];

        foreach ($this->phpFilesUnder(base_path('database/migrations')) as $absolutePath) {
            $basename = basename($absolutePath);

            foreach ($forbiddenMigrationPatterns as $pattern) {
                $this->assertDoesNotMatchRegularExpression(
                    $pattern,
                    $basename,
                    "Forbidden migration present: {$basename}",
                );
            }

            $contents = (string) file_get_contents($absolutePath);

            $this->assertStringNotContainsString("Schema::create('results'", $contents);
            $this->assertStringNotContainsString("Schema::create('outcomes'", $contents);
            $this->assertStringNotContainsString("Schema::create('task_outcomes'", $contents);
            $this->assertStringNotContainsString("Schema::create('task_results'", $contents);
            $this->assertStringNotContainsString("Schema::create('task_outcome_events'", $contents);
        }

        $this->assertFileExists(base_path('database/migrations/2026_08_10_112337_add_outcome_lifecycle_columns_to_tasks_table.php'));
        $this->assertFileExists(base_path('app/Support/Tasks/TaskOutcomeStatus.php'));
        $this->assertFileExists(base_path('app/Services/Tasks/TaskOutcomeEvaluator.php'));
    }

    /**
     * @return list<string>
     */
    private function phpFilesUnder(string $root): array
    {
        if (! is_dir($root)) {
            return [];
        }

        $iterator = new RegexIterator(
            new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root)),
            '/\.php$/i',
            RegexIterator::MATCH,
        );

        $files = [];
        foreach ($iterator as $file) {
            $files[] = $file->getPathname();
        }

        sort($files);

        return $files;
    }
}
