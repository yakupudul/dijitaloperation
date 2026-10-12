<?php

use App\Services\Ai\ClaudeApiWindow;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

/**
 * Claude marka denetimi (yakup, 2026-10-11): while the free Claude API period runs, the new operation joins it (Haiku in
 * the recommended plan; back to its default with the others when the period ends) and a first review is queued for
 * every active brand. Its proposals only wait on the Onarım masası.
 */
return new class extends Migration
{
    private const string SNAPSHOT = 'ai/claude-api-window.json';

    public function up(): void
    {
        if (! ClaudeApiWindow::active() || ! Schema::hasTable('prompt_versions') || ! Schema::hasTable('users')) {
            return;
        }
        try {
            $this->rememberDefault();
            Artisan::call('moxdop:ai:claude-api-window', ['action' => 'start']);
            Artisan::call('moxdop:brands:review');
        } catch (Throwable $exception) {
            // No admin / roles yet (a fresh database) or no queue: the nightly schedule starts it.
            report($exception);
        }
    }

    public function down(): void {}

    /** The period's snapshot learns the operation's model before the period (none), so its end moves it back. */
    private function rememberDefault(): void
    {
        $disk = Storage::disk('local');
        if (! $disk->exists(self::SNAPSHOT)) {
            return;
        }
        $snapshot = (array) json_decode((string) $disk->get(self::SNAPSHOT), true);
        if (! array_key_exists('brand.data_review', (array) ($snapshot['models'] ?? []))) {
            $snapshot['models']['brand.data_review'] = null;
            $disk->put(self::SNAPSHOT, json_encode($snapshot, JSON_PRETTY_PRINT) ?: '{}');
        }
    }
};
