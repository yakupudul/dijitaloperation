<?php

namespace App\Services\WhatsApp;

use App\Models\CoreIntegration;
use App\Models\Customer;
use App\Models\WhatsAppBackupLine;
use App\Models\WhatsAppConversation;
use App\Models\WhatsAppMessage;
use App\Services\WhatsApp\Backup\WhatsAppBackupImporter;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\HtmlString;

/**
 * WhatsApp konuşmaları (yakup, 2026-11-13): every WhatsApp chat MoxDOP holds, from every source at once (the live number,
 * the backup shown in the inbox and every other backup row in the archive), listed, searched in the message text and
 * read. The same answers feed the "WhatsApp konuşmaları" screen and Claude's `whatsapp-conversations` MCP tool, so
 * Claude can answer "hangi müşteri fatura istemiyorum dedi" without an Excel export.
 *
 * Message bodies are encrypted at rest, so the database cannot match them: the search decrypts in pages, newest first,
 * and matches every word of the query (Turkish case and accents ignored: "fatura" finds "Faturayı", "istemiyorum" finds
 * "İSTEMİYORUM"). It stops at the hit limit or the time budget and says so. Nothing here writes.
 */
final class ConversationArchive
{
    public const int MAX_HITS = 200;

    public const int MAX_READ = 200;

    private const array TYPES = [
        'text' => 'Metin', 'image' => 'Fotoğraf', 'audio' => 'Ses', 'video' => 'Video', 'document' => 'Belge',
        'sticker' => 'Çıkartma', 'location' => 'Konum', 'contacts' => 'Kişi kartı', 'gif' => 'GIF',
    ];

    /** @var array<string, string>|null */
    private ?array $labels = null;

    /**
     * Where chats come from: "live" (the Meta number), "line:<id>" for each backup row.
     *
     * @return list<array{key: string, label: string, conversations: int}>
     */
    public function sources(): array
    {
        $out = [];
        foreach ($this->labels() as $key => $label) {
            $out[] = ['key' => $key, 'label' => $label, 'conversations' => $this->scope($key)->count()];
        }

        return $out;
    }

    /**
     * Chats whose name or number contains $q (every chat when empty), newest first.
     *
     * @return Collection<int, array<string, mixed>>
     */
    public function conversations(string $q = '', ?string $source = null, int $limit = 50, int $offset = 0): Collection
    {
        $term = mb_substr(trim($q), 0, 100);
        $digits = preg_replace('/\D+/', '', $term);
        $rows = $this->scope($source)
            ->when($term !== '', fn (Builder $query) => $query->where(function (Builder $nested) use ($term, $digits): void {
                $nested->where('contact_name', 'like', '%'.$term.'%');
                if (strlen((string) $digits) >= 4) {
                    $nested->orWhere('contact_id', 'like', '%'.$digits.'%');
                }
                $customers = Customer::query()->where('name', 'like', '%'.$term.'%')->limit(50)->pluck('id');
                if ($customers->isNotEmpty()) {
                    $nested->orWhereIn('customer_id', $customers);
                }
            }))
            ->orderByDesc('last_message_at')->orderByDesc('id')
            ->skip(max(0, $offset))->take(max(1, min($limit, 200)))->get();
        $counts = WhatsAppMessage::query()->whereIn('conversation_id', $rows->pluck('id'))
            ->selectRaw('conversation_id, count(*) as n')->groupBy('conversation_id')->pluck('n', 'conversation_id');

        return $rows->map(fn (WhatsAppConversation $chat): array => $this->describe($chat) + ['messages' => (int) ($counts[$chat->id] ?? 0)]);
    }

    /**
     * Messages that contain every word of $q, newest first.
     *
     * @param  array{source?: ?string, conversation_id?: ?int, direction?: ?string, from?: ?CarbonInterface, to?: ?CarbonInterface, limit?: ?int, seconds?: ?float}  $filters
     * @return array{query: string, hits: list<array<string, mixed>>, scanned: int, complete: bool, more: bool}
     */
    public function search(string $q, array $filters = []): array
    {
        $words = self::words($q);
        $limit = max(1, min((int) ($filters['limit'] ?? 50), self::MAX_HITS));
        $budget = (float) ($filters['seconds'] ?? 20.0);
        if ($words === []) {
            return ['query' => $q, 'hits' => [], 'scanned' => 0, 'complete' => true, 'more' => false];
        }
        $chats = $this->scope($filters['source'] ?? null)
            ->when($filters['conversation_id'] ?? null, fn (Builder $query, int $id) => $query->whereKey($id))
            ->select('id');
        $started = microtime(true);
        $found = [];
        $scanned = 0;
        $complete = true;
        $more = false;
        WhatsAppMessage::query()->whereIn('conversation_id', $chats)->whereNull('redacted_at')
            ->when(in_array($filters['direction'] ?? null, ['incoming', 'outgoing'], true), fn (Builder $query) => $query->where('direction', $filters['direction']))
            ->when($filters['from'] ?? null, fn (Builder $query, CarbonInterface $from) => $query->where('sent_at', '>=', $from))
            ->when($filters['to'] ?? null, fn (Builder $query, CarbonInterface $to) => $query->where('sent_at', '<=', $to))
            ->select(['id', 'conversation_id', 'direction', 'message_type', 'body', 'sent_at'])
            ->chunkByIdDesc(500, function (Collection $page) use ($words, $limit, $budget, $started, &$found, &$scanned, &$complete, &$more): bool {
                foreach ($page as $message) {
                    $scanned++;
                    $text = (string) $message->body;
                    $folded = self::fold($text);
                    $at = null;
                    foreach ($words as $word) {
                        $position = mb_strpos($folded, $word);
                        if ($position === false) {
                            continue 2;
                        }
                        $at ??= $position;
                    }
                    if (count($found) >= $limit) {
                        $more = true;

                        return false;
                    }
                    $found[] = ['message' => $message, 'snippet' => self::snippet($text, (int) $at)];
                }
                if (microtime(true) - $started > $budget) {
                    $complete = false;

                    return false;
                }

                return true;
            });

        $chats = WhatsAppConversation::query()->whereKey(collect($found)->pluck('message.conversation_id')->unique())->get()->keyBy('id');
        $hits = collect($found)->sortByDesc(fn (array $hit) => $hit['message']->sent_at?->getTimestamp())->values()
            ->map(fn (array $hit): array => [
                'message_id' => $hit['message']->id,
                'at' => $this->time($hit['message']->sent_at),
                'direction' => $hit['message']->direction === 'outgoing' ? 'Moximu' : 'Kişi',
                'text' => $hit['snippet'],
            ] + $this->describe($chats[$hit['message']->conversation_id], short: true))->all();

        return ['query' => $q, 'hits' => $hits, 'scanned' => $scanned, 'complete' => $complete, 'more' => $more];
    }

    /**
     * One chat's messages, oldest first: the newest $limit, the $limit before message $before, or the ones around
     * message $around (a search hit with what came before and after it).
     *
     * @return array{conversation: array<string, mixed>, messages: list<array<string, mixed>>, older: ?int, newer: ?int}|null
     */
    public function read(int $conversationId, ?int $around = null, ?int $before = null, int $limit = 50): ?array
    {
        $chat = $this->scope(null)->whereKey($conversationId)->first();
        if ($chat === null) {
            return null;
        }
        $limit = max(1, min($limit, self::MAX_READ));
        $base = fn () => WhatsAppMessage::query()->where('conversation_id', $chat->id);
        if ($around !== null && ($pivot = $base()->whereKey($around)->first()) !== null) {
            $half = intdiv($limit, 2);
            $older = $base()->where(fn (Builder $q) => $q->where('sent_at', '<', $pivot->sent_at)->orWhere(fn (Builder $q) => $q->where('sent_at', $pivot->sent_at)->where('id', '<', $pivot->id)))
                ->orderByDesc('sent_at')->orderByDesc('id')->take($half)->get();
            $newer = $base()->where(fn (Builder $q) => $q->where('sent_at', '>', $pivot->sent_at)->orWhere(fn (Builder $q) => $q->where('sent_at', $pivot->sent_at)->where('id', '>', $pivot->id)))
                ->orderBy('sent_at')->orderBy('id')->take($limit - $half - 1)->get();
            $messages = $older->reverse()->push($pivot)->concat($newer)->values();
        } else {
            $query = $base()->orderByDesc('sent_at')->orderByDesc('id');
            if ($before !== null && ($edge = $base()->whereKey($before)->first()) !== null) {
                $query->where(fn (Builder $q) => $q->where('sent_at', '<', $edge->sent_at)->orWhere(fn (Builder $q) => $q->where('sent_at', $edge->sent_at)->where('id', '<', $edge->id)));
            }
            $messages = $query->take($limit)->get()->reverse()->values();
        }
        $first = $messages->first();
        $last = $messages->last();
        $hasOlder = $first !== null && $base()->where(fn (Builder $q) => $q->where('sent_at', '<', $first->sent_at)->orWhere(fn (Builder $q) => $q->where('sent_at', $first->sent_at)->where('id', '<', $first->id)))->exists();
        $hasNewer = $last !== null && $base()->where(fn (Builder $q) => $q->where('sent_at', '>', $last->sent_at)->orWhere(fn (Builder $q) => $q->where('sent_at', $last->sent_at)->where('id', '>', $last->id)))->exists();

        return [
            'conversation' => $this->describe($chat) + ['messages' => $base()->count()],
            'messages' => $messages->map(fn (WhatsAppMessage $message): array => [
                'id' => $message->id,
                'at' => $this->time($message->sent_at),
                'from' => $message->direction === 'outgoing' ? 'Moximu' : 'Kişi',
                'type' => $message->message_type === 'text' ? null : (self::TYPES[$message->message_type] ?? $message->message_type),
                'text' => $message->redacted_at !== null ? '(silindi)' : (string) $message->body,
            ])->all(),
            'older' => $hasOlder ? $first->id : null,
            'newer' => $hasNewer ? $last->id : null,
        ];
    }

    /** @return Builder<WhatsAppConversation> chats of one source key, or of every source when null */
    public function scope(?string $source): Builder
    {
        $live = CoreIntegration::query()->where('provider', WhatsAppConnection::PROVIDER)->value('id');
        $archive = WhatsAppBackupLine::archiveId();
        $query = WhatsAppConversation::query();
        if ($source === 'live') {
            return $query->where('integration_id', $live)->where('phone_number_id', '!=', WhatsAppBackupImporter::LINE);
        }
        if ($source !== null && preg_match('/^line:(\d+)$/', $source, $match) === 1) {
            $line = WhatsAppBackupLine::query()->find((int) $match[1]);

            return $line === null ? $query->whereRaw('1 = 0') : $line->conversations();
        }

        return $query->where(fn (Builder $nested) => $nested->where('integration_id', $live)
            ->when($archive !== null, fn (Builder $q) => $q->orWhere('integration_id', $archive)));
    }

    /** Lower case without Turkish accents, one character for one ("İ" → "i", "ş" → "s"), so positions stay the same. */
    public static function fold(string $text): string
    {
        return strtr(mb_strtolower(strtr($text, ['İ' => 'i', 'I' => 'ı'])), [
            'ı' => 'i', 'ş' => 's', 'ğ' => 'g', 'ü' => 'u', 'ö' => 'o', 'ç' => 'c', 'â' => 'a', 'î' => 'i', 'û' => 'u',
        ]);
    }

    /** @return list<string> the folded words of a query ("fatura istemiyorum" → both must be in the message) */
    public static function words(string $q): array
    {
        $words = preg_split('/\s+/u', self::fold(trim(mb_substr($q, 0, 200))), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        return array_values(array_unique(array_filter($words, fn (string $word): bool => mb_strlen($word) >= 2)));
    }

    /** The text, escaped, with every found word in <mark> (for the screen). */
    public static function highlight(string $text, array $words): HtmlString
    {
        $folded = self::fold($text);
        if ($words === [] || mb_strlen($folded) !== mb_strlen($text)) {
            return new HtmlString(e($text));
        }
        $marks = [];
        foreach ($words as $word) {
            $offset = 0;
            while (($position = mb_strpos($folded, $word, $offset)) !== false) {
                $marks[] = [$position, $position + mb_strlen($word)];
                $offset = $position + 1;
            }
        }
        usort($marks, fn (array $a, array $b): int => $a[0] <=> $b[0]);
        $html = '';
        $cursor = 0;
        foreach ($marks as [$start, $end]) {
            if ($end <= $cursor) {
                continue;
            }
            $start = max($start, $cursor);
            $html .= e(mb_substr($text, $cursor, $start - $cursor)).'<mark class="rounded bg-warning-100 px-0.5 text-inherit dark:bg-warning-500/30">'.e(mb_substr($text, $start, $end - $start)).'</mark>';
            $cursor = $end;
        }

        return new HtmlString($html.e(mb_substr($text, $cursor)));
    }

    private static function snippet(string $text, int $at): string
    {
        $text = (string) preg_replace('/\s+/u', ' ', $text);
        if (mb_strlen($text) <= 240) {
            return $text;
        }
        $start = max(0, $at - 90);

        return ($start > 0 ? '…' : '').mb_substr($text, $start, 240).($start + 240 < mb_strlen($text) ? '…' : '');
    }

    /** @return array<string, mixed> */
    private function describe(WhatsAppConversation $chat, bool $short = false): array
    {
        $customer = $chat->customer_id ? Customer::query()->whereKey($chat->customer_id)->value('name') : null;
        $row = [
            'conversation_id' => $chat->id,
            'name' => $chat->contact_name ?: null,
            'phone' => ctype_digit((string) $chat->contact_id) ? '+'.$chat->contact_id : null,
            'customer' => $customer,
            'source' => $this->sourceLabel($chat),
        ];
        if (! $short) {
            $row['last_message_at'] = $this->time($chat->last_message_at);
        }

        return $row;
    }

    private function sourceLabel(WhatsAppConversation $chat): string
    {
        $labels = $this->labels();
        if ($chat->phone_number_id === WhatsAppBackupImporter::LINE) {
            $shown = WhatsAppBackupLine::shown();

            return $shown !== null ? ($labels['line:'.$shown->id] ?? 'Yedek') : 'Yedek';
        }
        if (preg_match('/^backup:(\d+)$/', (string) $chat->phone_number_id, $match) === 1) {
            return $labels['line:'.$match[1]] ?? 'Yedek';
        }

        return $labels['live'];
    }

    /** @return array<string, string> */
    private function labels(): array
    {
        if ($this->labels === null) {
            $this->labels = ['live' => 'Canlı numara'];
            foreach (WhatsAppBackupLine::query()->orderBy('id')->get(['id', 'name']) as $line) {
                $this->labels['line:'.$line->id] = 'Yedek: '.$line->name;
            }
        }

        return $this->labels;
    }

    private function time(?CarbonInterface $at): ?string
    {
        return $at?->timezone('Europe/Istanbul')->format('Y-m-d H:i');
    }
}
