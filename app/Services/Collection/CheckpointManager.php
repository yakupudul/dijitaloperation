<?php

namespace App\Services\Collection;

use App\Models\Collection\CollectionDatasetRun;
use InvalidArgumentException;

/**
 * Checkpoint advances only after successful persistence boundary (Prompt 10).
 * Never stores secrets.
 */
final class CheckpointManager
{
    /**
     * @var list<string>
     */
    private const FORBIDDEN_KEYS = [
        'access_token',
        'refresh_token',
        'authorization',
        'client_secret',
        'api_secret',
        'token',
        'password',
    ];

    /**
     * @param  array<string, mixed>  $checkpoint
     */
    public function advance(CollectionDatasetRun $datasetRun, array $checkpoint): void
    {
        $this->assertSafe($checkpoint);

        $datasetRun->forceFill([
            'checkpoint' => $checkpoint,
            'last_activity_at' => now(),
        ])->save();
    }

    /**
     * @return array<string, mixed>
     */
    public function current(CollectionDatasetRun $datasetRun): array
    {
        return $datasetRun->checkpoint ?? [];
    }

    /**
     * @param  array<string, mixed>  $checkpoint
     */
    public function assertSafe(array $checkpoint): void
    {
        $stack = [$checkpoint];
        while ($stack !== []) {
            $node = array_pop($stack);
            foreach ($node as $key => $value) {
                $keyLower = strtolower((string) $key);
                foreach (self::FORBIDDEN_KEYS as $forbidden) {
                    if (str_contains($keyLower, $forbidden)) {
                        throw new InvalidArgumentException("Checkpoint must not contain secret key [{$key}]");
                    }
                }
                if (is_array($value)) {
                    $stack[] = $value;
                }
            }
        }
    }
}
