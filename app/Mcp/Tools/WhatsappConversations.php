<?php

namespace App\Mcp\Tools;

use App\Services\WhatsApp\ConversationArchive;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;

/**
 * WhatsApp konuşmaları for Claude (yakup, 2026-11-13): search and read every WhatsApp chat MoxDOP holds (live number and
 * every backup row). Patient and customer chats are health data: this tool is deliberately NOT marked read-only, so a
 * read-only MCP key (one handed to a helper) cannot open them; only the operator's own write key can. Nothing is written.
 */
#[Name('whatsapp-conversations')]
#[Description('WhatsApp konuşmaları: every WhatsApp chat MoxDOP holds (the live number and every phone backup), to answer the operator\'s questions about what someone wrote ("hangi müşteri fatura istemiyorum dedi", "Merve Hanım ne zaman ödeme yapacağını söyledi"). '
    .'action=search q=<words> finds messages containing EVERY word (Turkish case and accents ignored; write word stems: "fatura" also finds "faturasız", "istem" finds "istemiyorum" and "istemediğiniz"); filters: direction (incoming = the person wrote it, outgoing = Moximu wrote it), from / to (YYYY-MM-DD), source, conversation_id. Each hit has conversation_id and message_id. '
    .'action=read conversation_id=… shows a chat oldest first (around=message_id to see a hit with what came before and after; before=message_id for older pages). '
    .'action=list q=<name, number or customer> finds chats by person; action=sources lists the sources. '
    .'Chats are private health data: quote only what the operator asked for, never copy them into notes, findings or brand facts.')]
class WhatsappConversations extends Tool
{
    public function handle(Request $request, ConversationArchive $archive): Response
    {
        $validated = $request->validate([
            'action' => ['required', 'in:search,read,list,sources'],
            'q' => ['nullable', 'string', 'max:200'],
            'conversation_id' => ['nullable', 'integer', 'min:1'],
            'around' => ['nullable', 'integer', 'min:1'],
            'before' => ['nullable', 'integer', 'min:1'],
            'direction' => ['nullable', 'in:incoming,outgoing'],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d'],
            'source' => ['nullable', 'string', 'regex:/^(live|line:\d+)$/'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:200'],
            'offset' => ['nullable', 'integer', 'min:0'],
        ]);
        $json = fn (array $value): Response => Response::text((string) json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));

        switch ($validated['action']) {
            case 'sources':
                return $json(['sources' => $archive->sources()]);
            case 'list':
                return $json(['conversations' => $archive->conversations((string) ($validated['q'] ?? ''), $validated['source'] ?? null, (int) ($validated['limit'] ?? 50), (int) ($validated['offset'] ?? 0))->all()]);
            case 'read':
                if (! isset($validated['conversation_id'])) {
                    return Response::error('read: conversation_id gerekli (search ya da list sonucundan).');
                }
                $chat = $archive->read((int) $validated['conversation_id'], $validated['around'] ?? null, $validated['before'] ?? null, (int) ($validated['limit'] ?? 60));

                return $chat === null ? Response::error('Bu numarada bir WhatsApp görüşmesi yok.') : $json($chat);
            default:
                if (ConversationArchive::words((string) ($validated['q'] ?? '')) === []) {
                    return Response::error('search: q en az bir kelime (2+ harf) olmalı.');
                }
                $tz = 'Europe/Istanbul';
                $result = $archive->search((string) $validated['q'], [
                    'source' => $validated['source'] ?? null,
                    'conversation_id' => $validated['conversation_id'] ?? null,
                    'direction' => $validated['direction'] ?? null,
                    'from' => isset($validated['from']) ? CarbonImmutable::parse($validated['from'], $tz)->startOfDay()->utc() : null,
                    'to' => isset($validated['to']) ? CarbonImmutable::parse($validated['to'], $tz)->endOfDay()->utc() : null,
                    'limit' => (int) ($validated['limit'] ?? 50),
                ]);
                if (! $result['complete']) {
                    $result['note'] = 'Süre doldu: en yeni '.$result['scanned'].' mesaja bakıldı. Daha eskisi için from/to ya da source ile daraltın.';
                } elseif ($result['more']) {
                    $result['note'] = 'Sınıra ulaşıldı; daha fazla eşleşme var. limit artırın ya da from/to, direction ile daraltın.';
                }

                return $json($result);
        }
    }

    /** @return array<string, mixed> */
    public function schema(JsonSchema $schema): array
    {
        return [
            'action' => $schema->string()->enum(['search', 'read', 'list', 'sources'])->required(),
            'q' => $schema->string()->description('search: the words that must all be in the message. list: part of a name, number or customer name (empty = newest chats).'),
            'conversation_id' => $schema->integer()->description('read: the chat. search: only this chat.'),
            'around' => $schema->integer()->description('read: a message_id from search; shows it with the messages before and after it.'),
            'before' => $schema->integer()->description('read: the "older" value of the previous page.'),
            'direction' => $schema->string()->enum(['incoming', 'outgoing'])->description('search: incoming = written by the person, outgoing = written by Moximu.'),
            'from' => $schema->string()->description('search: first day, YYYY-MM-DD (Istanbul).'),
            'to' => $schema->string()->description('search: last day, YYYY-MM-DD (Istanbul).'),
            'source' => $schema->string()->description('search / list: "live" or "line:<id>" from action=sources (empty = every source).'),
            'limit' => $schema->integer()->description('search: hits (default 50, max 200). read: messages (default 60, max 200). list: chats (default 50).'),
            'offset' => $schema->integer()->description('list: skip this many chats.'),
        ];
    }
}
