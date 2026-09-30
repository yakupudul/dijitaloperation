<?php

namespace App\Console\Commands;

use App\Services\Queries\QueryRuleEngine;
use Illuminate\Console\Command;

/** moxdop:queries:rules — recomputes the variant / topic keys of every query with the current rules (config/moxdop-query-rules.php). */
final class QueriesApplyRulesCommand extends Command
{
    protected $signature = 'moxdop:queries:rules';

    protected $description = 'Sorgu kural motorunu (varyant / konu anahtarları) tüm sorgulara uygular.';

    public function handle(QueryRuleEngine $engine): int
    {
        $result = $engine->apply();
        $this->info(sprintf('Kural sürümü v%d: %d sorgu, %d değişti.', QueryRuleEngine::version(), $result['queries'], $result['changed']));

        return self::SUCCESS;
    }
}
