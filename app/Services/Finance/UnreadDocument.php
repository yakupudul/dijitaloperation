<?php

namespace App\Services\Finance;

use RuntimeException;

/** A mail attachment that could not be read now (the API is away): it is not taken, the mail is read again later. */
final class UnreadDocument extends RuntimeException {}
