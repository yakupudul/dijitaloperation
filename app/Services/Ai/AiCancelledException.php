<?php

namespace App\Services\Ai;

use Illuminate\Contracts\Debug\ShouldntReport;
use RuntimeException;

/**
 * The operator stopped this AI job (AI işleri › Durdur). Thrown between AI calls; the queued job ends quietly and its
 * row is marked "Durduruldu". Never reported as an error.
 */
final class AiCancelledException extends RuntimeException implements ShouldntReport
{
    public function __construct(string $message = 'AI işi operatör tarafından durduruldu.')
    {
        parent::__construct($message);
    }
}
