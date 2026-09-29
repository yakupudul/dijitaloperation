<?php

use App\Services\Ai\AiPricing;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * OpenAI calls were recorded with cost_usd NULL ("price unknown") because the pricing table had no OpenAI models,
 * so the usage screen showed $0.00 and the monthly budget never moved. Now that the prices exist, the recorded
 * token counts are priced once. Rows whose model is still unknown stay NULL. Idempotent (only NULL rows).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('ai_usage_records')) {
            return;
        }
        $pricing = app(AiPricing::class);
        DB::table('ai_usage_records')->whereNull('cost_usd')->orderBy('id')
            ->select(['id', 'provider', 'model', 'input_tokens', 'output_tokens', 'cache_read_tokens', 'cache_write_tokens'])
            ->chunkById(500, function ($rows) use ($pricing): void {
                foreach ($rows as $row) {
                    $cost = $pricing->cost((string) $row->provider, (string) $row->model, (int) $row->input_tokens, (int) $row->output_tokens,
                        (int) $row->cache_read_tokens, (int) $row->cache_write_tokens);
                    if ($cost !== null) {
                        DB::table('ai_usage_records')->where('id', $row->id)->update(['cost_usd' => $cost]);
                    }
                }
            });
    }

    public function down(): void
    {
        // Prices stay; nothing to undo.
    }
};
