<?php

namespace App\Livewire\Operator\Work\Concerns;

use App\Services\Work\ContentBoard;
use Illuminate\Validation\ValidationException;

/**
 * İçerik fikirleri buttons shared by Genel işler and the website İçerik tab: "Onayla ve yazdır" (in the picked
 * language), "+ dil yaz", "Oku" (reader) and "WordPress'e taslak gönder".
 */
trait ActsOnContentIdeas
{
    /** @var array<int|string, string> content idea id => language picked before writing */
    public array $languages = [];

    /** Content idea whose article is open to read. */
    public ?int $reading = null;

    public function writeContent(int $id, ?string $language = null): void
    {
        if (! $this->ownsContentIdea($id)) {
            return;
        }
        $picked = $language ?? (filled($this->languages[$id] ?? null) ? (string) $this->languages[$id] : null);
        $this->contentStep(fn (): string => app(ContentBoard::class)->write($id, auth()->user(), $picked));
    }

    public function sendContent(int $id): void
    {
        if (! $this->ownsContentIdea($id)) {
            return;
        }
        $this->contentStep(fn (): string => app(ContentBoard::class)->send($id, auth()->user()));
        $this->reading = null;
    }

    public function read(int $id): void
    {
        $this->reading = $this->ownsContentIdea($id) ? $id : null;
    }

    public function closeReading(): void
    {
        $this->reading = null;
    }

    /** A screen limited to one site ignores ideas of other sites. */
    protected function ownsContentIdea(int $id): bool
    {
        return true;
    }

    /** @param  callable(): string  $step */
    private function contentStep(callable $step): void
    {
        abort_unless(auth()->user()?->is_active, 403);
        try {
            $this->message = $step();
        } catch (ValidationException $exception) {
            $this->message = (string) collect($exception->errors())->flatten()->first();
        }
    }
}
