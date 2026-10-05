<?php

namespace App\Services\WhatsApp\Backup;

use RuntimeException;

/** The file is not a readable crypt15 backup; the message is Turkish and shown to the operator as is. */
final class WhatsAppBackupFormatException extends RuntimeException {}
