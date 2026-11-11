<?php

namespace App\Mcp\Tools;

use App\Services\Brain\Playbooks as Books;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Validation\Rule;
use InvalidArgumentException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;

#[Description('Beyin › Kitaplar: the books that hold how this agency works, so every new session looks at a brand with the same depth. Master books (seo, aeo, google-ads, meta) and sector books (sektor-…). action=list gives each book with its "when" line and size; action=get (keys, at most 3) returns the text with its lessons. Before you look at a brand or do a finding, load ONLY the books of that job: the master book of the craft plus the sector book of the brand; never all of them. action=lesson adds one rule (one or two sentences) to a book and is allowed in two cases only: source=duzeltme when the operator corrected you (evidence = his words) or source=olcum when a monthly measurement showed it (evidence = brand, period, before → after). Never add a lesson from your own opinion or from a single unmeasured case. action=save writes a NEW sector book (key starts with sektor-, title, when, body) when a brand has a sector no book covers; existing books are edited only by the operator in Ayarlar › Kitaplar. A book is at most 12.000 characters.')]
class Playbooks extends Tool
{
    public function handle(Request $request, Books $books): Response
    {
        $validated = $request->validate([
            'action' => ['required', Rule::in(['list', 'get', 'lesson', 'save'])],
            'keys' => ['required_if:action,get', 'nullable', 'array', 'max:3'], 'keys.*' => ['string', 'max:60'],
            'key' => ['required_if:action,lesson', 'required_if:action,save', 'nullable', 'string', 'max:60'],
            'text' => ['required_if:action,lesson', 'nullable', 'string', 'max:400'], 'source' => ['nullable', 'string'],
            'evidence' => ['nullable', 'string', 'max:400'], 'title' => ['nullable', 'string', 'max:120'], 'when' => ['nullable', 'string', 'max:300'],
            'body' => ['required_if:action,save', 'nullable', 'string'],
        ]);
        try {
            if ($validated['action'] === 'list') {
                return Response::json(['books' => array_map(fn (array $b): array => ['key' => $b['key'], 'title' => $b['title'], 'when' => $b['when'], 'characters' => $b['size'],
                    'lessons' => count($b['lessons']), 'edited_by_operator' => $b['edited']], $books->all())]);
            }
            if ($validated['action'] === 'get') {
                $found = [];
                foreach ((array) $validated['keys'] as $key) {
                    $book = $books->get((string) $key);
                    $found[(string) $key] = $book !== null ? $books->text($book) : 'Bu adla kitap yok.';
                }

                return Response::json(['books' => $found]);
            }
            if ($validated['action'] === 'lesson') {
                $lesson = $books->lesson((string) $validated['key'], (string) $validated['text'], (string) ($validated['source'] ?? ''), (string) ($validated['evidence'] ?? ''), 'Claude');

                return Response::text('Ders "'.$validated['key'].'" kitabına girdi: '.$lesson['text']);
            }
            $key = (string) $validated['key'];
            if (! str_starts_with($key, 'sektor-') || $books->get($key) !== null) {
                return Response::error('save yalnız yeni bir sektör kitabı yazar (key sektor- ile başlar ve henüz yoktur). Var olan kitabı operatör Ayarlar › Kitaplar\'da düzenler; sen ders ekleyebilirsin.');
            }
            $books->save($key, (string) $validated['body'], 'Claude', $validated['title'] ?? null, $validated['when'] ?? null);

            return Response::text('Kitap yazıldı: '.$key);
        } catch (InvalidArgumentException $error) {
            return Response::error($error->getMessage());
        }
    }

    /** @return array<string, mixed> */
    public function schema(JsonSchema $schema): array
    {
        return [
            'action' => $schema->string()->enum(['list', 'get', 'lesson', 'save'])->description('list | get | lesson | save')->required(),
            'keys' => $schema->array()->items($schema->string())->description('get: the books to read (at most 3).'),
            'key' => $schema->string()->description('lesson / save: the book.'),
            'text' => $schema->string()->description('lesson: the rule, one or two plain Turkish sentences.'),
            'source' => $schema->string()->enum(array_keys(Books::SOURCES))->description('lesson: duzeltme (the operator corrected you) | olcum (a measured monthly result).'),
            'evidence' => $schema->string()->description('lesson: his words, or brand + period + before → after.'),
            'title' => $schema->string()->description('save: the new sector book title.'),
            'when' => $schema->string()->description('save: when the book is loaded, one line.'),
            'body' => $schema->string()->description('save: the book in Turkish Markdown (## headings, short rules).'),
        ];
    }
}
