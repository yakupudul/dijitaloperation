<?php

namespace App\Support\Database;

use Illuminate\Database\PostgresConnection;
use Illuminate\Database\Schema\Grammars\PostgresGrammar;

/**
 * PostgreSQL connection whose Schema::hasTable() also sees views. Compact fact storage (moxdop:db:compact) swaps a
 * logical table for a view with the same columns; readers guarded by Schema::hasTable() must keep working.
 */
class ViewAwarePostgresConnection extends PostgresConnection
{
    protected function getDefaultSchemaGrammar(): PostgresGrammar
    {
        return new class($this) extends PostgresGrammar
        {
            public function compileTableExists($schema, $table): string
            {
                return str_replace("c.relkind in ('r', 'p')", "c.relkind in ('r', 'p', 'v', 'm')", parent::compileTableExists($schema, $table));
            }
        };
    }
}
