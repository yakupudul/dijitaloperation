<?php

namespace App\Services\Ai;

use Illuminate\Contracts\Debug\ShouldntReport;
use RuntimeException;

/**
 * An AI call that must not start: the day's AI ceiling is spent, or work nobody clicked outside the areas allowed to
 * run by themselves (Sorgular). Thrown before the call; nothing is paid. Never reported as an error.
 */
final class AiBudgetExceededException extends RuntimeException implements ShouldntReport {}
