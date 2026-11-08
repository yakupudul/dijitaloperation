<?php

namespace App\Services\Sales;

use App\Services\WhatsApp\WhatsAppConnection;

/**
 * Sabit kararlar (yakup 2026-11-08): the operator's standing rules for every customer-facing text (prices, what is
 * never done or written, how a prospect message is built, known prospects). One text, edited on WhatsApp › Beyin and
 * stored as the WhatsApp integration's business_context; read by WhatsApp reply drafts, prospect messages, prospect
 * analyses and prospect research, where it wins over everything else. The file is the starting text.
 */
final class StandingRules
{
    public const string FILE = 'prompts/moximu-standing-rules.md';

    public static function text(): string
    {
        $stored = trim((string) data_get(app(WhatsAppConnection::class)->integration()?->config, 'business_context', ''));

        return $stored !== '' ? $stored : self::initial();
    }

    public static function initial(): string
    {
        return trim((string) @file_get_contents(resource_path(self::FILE)));
    }
}
