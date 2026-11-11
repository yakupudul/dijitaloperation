<?php

namespace App\Services\Brain;

use App\Models\Playbook;
use App\Support\SchemaState;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Beyin › Kitaplar (Marka Düzeni 2.4). What Claude has to know to look at a brand with the same depth in every new
 * session lives here, not in a chat: a master book per craft (SEO, AEO, Google Ads, Meta) and a book per sector. The
 * first versions ship with the app (`resources/playbooks/*.md`); the operator edits them in Ayarlar › Kitaplar, and a
 * lesson enters a book only from his correction or from a measured monthly result. Books have a size limit so a
 * session loads only what the job needs: Claude asks for the list, then reads the one or two books of the job.
 */
final class Playbooks
{
    /** A book's text limit (characters): above this it is not a book a session can load beside the data. */
    public const int MAX_BODY = 12000;

    public const int MAX_LESSONS = 25;

    public const int MAX_LESSON = 400;

    /** Where a lesson may come from. */
    public const array SOURCES = ['duzeltme' => 'Senin düzeltmen', 'olcum' => 'Ölçülmüş sonuç'];

    /**
     * Every book, shipped ones first in their fixed order.
     *
     * @return list<array<string, mixed>>
     */
    public function all(): array
    {
        $rows = SchemaState::hasTable('playbooks') ? Playbook::query()->get()->keyBy('key') : collect();
        $books = [];
        foreach ($this->shipped() as $key => $file) {
            $books[$key] = $this->shape((string) $key, $file, $rows->get($key));
        }
        foreach ($rows as $key => $row) {
            if (! isset($books[$key])) {
                $books[$key] = $this->shape((string) $key, null, $row);
            }
        }

        return array_values($books);
    }

    /** @return array<string, mixed>|null */
    public function get(string $key): ?array
    {
        return collect($this->all())->firstWhere('key', $key);
    }

    /** The operator's edit of a book's text (or a new book). The text before the edit is kept for one step back. */
    public function save(string $key, string $body, string $by, ?string $title = null, ?string $when = null): Playbook
    {
        $key = Str::slug($key);
        $body = trim(str_replace("\r\n", "\n", $body));
        $current = $this->get($key);
        if ($key === '' || mb_strlen($key) > 60) {
            throw new InvalidArgumentException('Kitabın kısa bir adı olmalı (en çok 60 karakter).');
        }
        if ($body === '') {
            throw new InvalidArgumentException('Kitap boş kaydedilmez.');
        }
        if (mb_strlen($body) > self::MAX_BODY) {
            throw new InvalidArgumentException('Kitap '.number_format(self::MAX_BODY, 0, ',', '.').' karakteri geçemez (şu an '.number_format(mb_strlen($body), 0, ',', '.').'). Kısalt ya da ikiye böl.');
        }
        if ($current === null && trim((string) $title) === '') {
            throw new InvalidArgumentException('Yeni kitabın başlığı gerekli.');
        }

        return Playbook::query()->updateOrCreate(['key' => $key], [
            'title' => mb_substr(trim((string) ($title ?: $current['title'])), 0, 120),
            'when' => mb_substr(trim((string) ($when ?? $current['when'] ?? '')), 0, 300) ?: null,
            'previous_body' => $current['body'] ?? null, 'body' => $body, 'edited_by' => mb_substr($by, 0, 60),
        ]);
    }

    /** One step back: the text before the last edit (the shipped text when there was none). */
    public function undo(string $key): void
    {
        $row = Playbook::query()->where('key', $key)->first();
        if ($row === null || $row->previous_body === null) {
            return;
        }
        $row->forceFill(['body' => $row->previous_body, 'previous_body' => $row->body])->save();
    }

    /**
     * A lesson enters a book: one rule in one or two sentences, with where it comes from.
     *
     * @return array<string, string> the stored lesson
     */
    public function lesson(string $key, string $text, string $source, string $evidence, string $by): array
    {
        $book = $this->get($key);
        $text = trim($text);
        $evidence = trim($evidence);
        if ($book === null) {
            throw new InvalidArgumentException('"'.$key.'" adında kitap yok.');
        }
        if (! isset(self::SOURCES[$source])) {
            throw new InvalidArgumentException('Ders yalnız operatörün düzeltmesinden (duzeltme) ya da ölçülmüş bir sonuçtan (olcum) girer.');
        }
        if ($text === '' || mb_strlen($text) > self::MAX_LESSON) {
            throw new InvalidArgumentException('Ders bir iki cümledir (en çok '.self::MAX_LESSON.' karakter).');
        }
        if ($evidence === '') {
            throw new InvalidArgumentException('Dersin dayanağı gerekli: operatörün sözü ya da ölçülen sayı (marka, dönem, önce → sonra).');
        }
        if (count($book['lessons']) >= self::MAX_LESSONS) {
            throw new InvalidArgumentException('Bu kitapta '.self::MAX_LESSONS.' ders var. Önce Ayarlar › Kitaplar ekranında dersleri kitabın metnine işleyip listeyi boşalt.');
        }
        $lesson = ['id' => Str::lower(Str::random(8)), 'text' => $text, 'source' => $source, 'evidence' => mb_substr($evidence, 0, 400), 'by' => mb_substr($by, 0, 60), 'at' => now('Europe/Istanbul')->toDateString()];
        $row = Playbook::query()->firstOrNew(['key' => $key], ['title' => $book['title'], 'when' => $book['when'] ?: null]);
        $row->lessons = array_merge((array) $row->lessons, [$lesson]);
        $row->save();

        return $lesson;
    }

    public function dropLesson(string $key, string $id): void
    {
        $row = Playbook::query()->where('key', $key)->first();
        if ($row !== null) {
            $row->forceFill(['lessons' => array_values(array_filter((array) $row->lessons, fn (array $l): bool => ($l['id'] ?? '') !== $id))])->save();
        }
    }

    /**
     * The whole book as Claude reads it: the text, then the lessons.
     *
     * @param  array<string, mixed>  $book
     */
    public function text(array $book): string
    {
        $lessons = collect($book['lessons'])->map(fn (array $l): string => '- '.$l['text'].' ('.self::SOURCES[$l['source']].', '.$l['at'].': '.$l['evidence'].')')->implode("\n");

        return '# '.$book['title']."\n".($book['when'] !== '' ? 'Ne zaman: '.$book['when']."\n" : '')."\n".$book['body']
            .($lessons !== '' ? "\n\n## Dersler (metinden sonra girenler; metinle çelişirse ders geçerlidir)\n".$lessons : '');
    }

    /** @return array<string, array{title: string, when: string, body: string}> */
    private function shipped(): array
    {
        $order = ['seo', 'aeo', 'google-ads', 'meta'];
        $files = collect(File::glob(resource_path('playbooks/*.md')))->mapWithKeys(function (string $path): array {
            $lines = explode("\n", str_replace("\r\n", "\n", (string) File::get($path)));
            $title = trim(ltrim((string) array_shift($lines), '# '));
            $when = '';
            if (str_starts_with((string) ($lines[0] ?? ''), 'Ne zaman:')) {
                $when = trim(mb_substr((string) array_shift($lines), 9));
            }

            return [basename($path, '.md') => ['title' => $title, 'when' => $when, 'body' => trim(implode("\n", $lines))]];
        });

        return $files->sortBy(fn (array $file, string $key): string => (($i = array_search($key, $order, true)) !== false ? '0'.$i : '1').$key)->all();
    }

    /**
     * @param  array{title: string, when: string, body: string}|null  $file
     * @return array<string, mixed>
     */
    private function shape(string $key, ?array $file, ?Playbook $row): array
    {
        $body = (string) ($row?->body ?? $file['body'] ?? '');

        return [
            'key' => $key, 'title' => (string) ($row?->title ?: $file['title'] ?? $key), 'when' => (string) ($row?->when ?: $file['when'] ?? ''), 'body' => $body,
            'lessons' => array_values((array) ($row?->lessons ?? [])), 'edited' => $row?->body !== null, 'edited_by' => $row?->edited_by,
            'updated_at' => $row?->updated_at?->timezone('Europe/Istanbul')->format('d.m.Y H:i'), 'size' => mb_strlen($body), 'shipped' => $file !== null,
            'can_undo' => $row?->previous_body !== null,
        ];
    }
}
