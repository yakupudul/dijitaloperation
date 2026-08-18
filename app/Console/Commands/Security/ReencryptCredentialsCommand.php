<?php

namespace App\Console\Commands\Security;

use App\Enums\Security\SecurityAuditEventKind;
use App\Models\CoreConnectionCredential;
use App\Models\CoreIntegrationCredential;
use App\Services\Security\SecurityAuditRecorder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Safe credential re-encryption under current APP_KEY (Prompt 64).
 * Uses framework Crypt / encrypted casts. Never prints secrets or keys.
 * Keys come from environment only — never CLI arguments.
 */
final class ReencryptCredentialsCommand extends Command
{
    protected $signature = 'moxdop:security:reencrypt-credentials
        {--dry-run : Report counts without writing}
        {--batch=50 : Records per batch}
        {--limit=0 : Max records (0 = all)}';

    protected $description = 'Re-encrypt CoreIntegrationCredential and CoreConnectionCredential payloads with the current APP_KEY';

    public function handle(SecurityAuditRecorder $audit): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $batch = max(1, (int) $this->option('batch'));
        $limit = max(0, (int) $this->option('limit'));

        $this->info('Credential re-encryption '.($dryRun ? '(dry-run)' : '(write)'));
        $this->line('Keys: environment APP_KEY / APP_PREVIOUS_KEYS only — never CLI.');

        $integrationUpdated = 0;
        $connectionUpdated = 0;
        $failed = 0;

        $integrationQuery = CoreIntegrationCredential::query()->orderBy('id');
        if ($limit > 0) {
            $integrationQuery->limit($limit);
        }

        $integrationQuery->chunkById($batch, function ($rows) use ($dryRun, &$integrationUpdated, &$failed): void {
            foreach ($rows as $row) {
                try {
                    $payload = $row->encrypted_payload;
                    if (! is_array($payload)) {
                        continue;
                    }
                    if ($dryRun) {
                        // Touch decrypt path only.
                        $integrationUpdated++;

                        continue;
                    }
                    DB::transaction(function () use ($row, $payload): void {
                        $row->encrypted_payload = $payload;
                        $row->save();
                    });
                    $integrationUpdated++;
                } catch (Throwable) {
                    $failed++;
                    $this->warn('integration_credential_id='.$row->id.' FAILED (no secret printed)');
                }
            }
        });

        $connectionQuery = CoreConnectionCredential::query()->orderBy('id');
        if ($limit > 0) {
            $connectionQuery->limit($limit);
        }
        $connectionQuery->chunkById($batch, function ($rows) use ($dryRun, &$connectionUpdated, &$failed): void {
            foreach ($rows as $row) {
                try {
                    $payload = $row->encrypted_payload;
                    if (! is_array($payload)) {
                        continue;
                    }
                    if ($dryRun) {
                        $connectionUpdated++;

                        continue;
                    }
                    DB::transaction(function () use ($row, $payload): void {
                        $row->encrypted_payload = $payload;
                        $row->save();
                    });
                    $connectionUpdated++;
                } catch (Throwable) {
                    $failed++;
                    $this->warn('connection_credential_id='.$row->id.' FAILED (no secret printed)');
                }
            }
        });

        $this->info("integration_credentials={$integrationUpdated} connection_credentials={$connectionUpdated} failed={$failed}");

        if (! $dryRun) {
            $audit->record(
                SecurityAuditEventKind::EncryptionReencryptBatch,
                reason: 'REENCRYPT_COMMAND',
                metadata: [
                    'integration_updated' => $integrationUpdated,
                    'connection_updated' => $connectionUpdated,
                    'failed' => $failed,
                    // Prove Crypt is available without printing key material.
                    'crypt_driver' => class_exists(Crypt::class) ? 'laravel' : 'missing',
                ],
            );
        }

        return $failed > 0 ? self::FAILURE : self::SUCCESS;
    }
}
