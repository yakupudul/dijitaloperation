<?php

namespace App\Services\AiTasks;

use RuntimeException;
use Throwable;

/** An AI API error that stops the API worker for a while (the reason is shown on Ayarlar › AI). */
final class ApiWorkerStop extends RuntimeException
{
    public function __construct(string $message, public readonly int $minutes = 60, ?Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }
}
