<?php

namespace App\Services\WhatsApp;

use RuntimeException;

final class WhatsAppGraphException extends RuntimeException
{
    public function __construct(public readonly array $details)
    {
        parent::__construct('whatsapp_graph_failed');
    }
}
