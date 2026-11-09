<?php

namespace App\Services\Sales;

use App\Models\CoreIntegration;
use App\Services\WhatsApp\WhatsAppConnection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

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

    /** Satış › Ayarlar › Sabit kararlar: the same text WhatsApp › Beyin edits; open chats' drafts are written again. */
    public static function save(string $text): void
    {
        $text = trim($text);
        if ($text === '' || mb_strlen($text) > 12000) {
            throw ValidationException::withMessages(['rules' => 'Sabit kararlar boş olamaz, en çok 12.000 karakter.']);
        }
        self::change(function (array $config) use ($text): array {
            $config['business_context'] = $text;

            return $config;
        });
    }

    /**
     * Questions the WhatsApp brain still asks the operator (prices, rules it could not learn from the chats).
     *
     * @return list<string>
     */
    public static function brainQuestions(): array
    {
        return array_values(array_filter((array) data_get(app(WhatsAppConnection::class)->integration()?->config, 'brain.open_questions', []), fn ($q): bool => is_string($q) && trim($q) !== ''));
    }

    /** An answer to one of the brain's questions joins the Sabit kararlar and the question leaves the list. */
    public static function answerBrainQuestion(string $question, string $answer): void
    {
        $answer = trim($answer);
        if ($answer === '' || mb_strlen($answer) > 2000) {
            throw ValidationException::withMessages(['rules' => 'Cevap boş olamaz, en çok 2000 karakter.']);
        }
        self::change(function (array $config) use ($question, $answer): array {
            $questions = array_values((array) data_get($config, 'brain.open_questions', []));
            if (! in_array($question, $questions, true)) {
                return $config;
            }
            $context = trim((string) ($config['business_context'] ?? '')) ?: self::initial();
            $config['business_context'] = mb_substr(trim($context."\n\n- ".$question.' → '.$answer), 0, 12000);
            data_set($config, 'brain.open_questions', array_values(array_filter($questions, fn ($q): bool => $q !== $question)));

            return $config;
        });
    }

    /** @param  callable(array<string, mixed>): array<string, mixed>  $change */
    private static function change(callable $change): void
    {
        $integration = app(WhatsAppConnection::class)->integration() ?? CoreIntegration::query()->firstOrCreate(['provider' => WhatsAppConnection::PROVIDER], [
            'name' => 'WhatsApp Business', 'status' => CoreIntegration::STATUS_ACTIVE, 'config' => [],
        ]);
        DB::transaction(function () use ($integration, $change): void {
            $current = CoreIntegration::query()->lockForUpdate()->findOrFail($integration->id);
            $config = $current->config ?? [];
            $next = $change($config);
            if ($next !== $config) {
                $current->update(['config' => $next]);
                WhatsAppConnection::contextChanged($current);
            }
        });
    }
}
