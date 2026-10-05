<?php

namespace App\Exceptions\Integrations;

use RuntimeException;

/**
 * A Business Profile read that did not deliver: Google refused it (HTTP error, disabled API, no access), the shared
 * Google client failed (token, scope, network), MoxDOP's own pacing / time budget stopped it, or Google returned
 * nothing usable. The data is unavailable, the code is not broken: collectors record it on the dataset and never
 * report() it. Any other exception during a Business Profile collection is a software error and is reported.
 */
final class GoogleBusinessProfileRequestException extends RuntimeException {}
