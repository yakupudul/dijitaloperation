<?php

namespace App\Services\WhatsApp\Backup;

use RuntimeException;

/** The 64-digit key did not open the backup; the uploaded file is kept so another key can be tried. */
final class WhatsAppBackupKeyException extends RuntimeException {}
