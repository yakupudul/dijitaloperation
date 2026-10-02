<?php

namespace App\Support\Console;

use InvalidArgumentException;

/** A --brand / --asset option matched no record or several; the message is shown to the operator as is. */
final class ConsoleScopeException extends InvalidArgumentException {}
