<?php

namespace App\Services\Measurement;

use RuntimeException;

/** A measurement run stopped before the MCP call times out, waiting for the Tag Manager per-minute quota. */
final class GtmQuotaPause extends RuntimeException {}
