<?php

namespace App\Services\Collection;

use App\Enums\Collection\CollectionErrorCategory;
use App\Models\Collection\CollectionDatasetRun;

final class CollectionErrorRecorder
{
    /**
     * @var list<string>
     */
    private const BLOCKED = [
        'access_token',
        'refresh_token',
        'authorization',
        'client_secret',
        'api_secret',
        'bearer ',
        'eaag',
        'password',
    ];

    public function sanitizeMessage(?string $message, string $fallback = 'Collection error'): string
    {
        $message = trim((string) $message);
        if ($message === '') {
            return $fallback;
        }

        $lower = strtolower($message);
        foreach (self::BLOCKED as $needle) {
            if (str_contains($lower, $needle)) {
                return $fallback;
            }
        }

        if (mb_strlen($message) > 480) {
            return mb_substr($message, 0, 479).'…';
        }

        return $message;
    }

    public function record(
        CollectionDatasetRun $datasetRun,
        CollectionErrorCategory $category,
        ?string $message,
        ?string $code = null,
    ): void {
        $datasetRun->forceFill([
            'error_category' => $category,
            'error_code' => $code,
            'error_message' => $this->sanitizeMessage($message),
            'last_activity_at' => now(),
        ])->save();
    }
}
