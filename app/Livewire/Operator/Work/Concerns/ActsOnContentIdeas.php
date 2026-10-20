<?php

namespace App\Livewire\Operator\Work\Concerns;

use App\Services\Work\ContentBoard;
use App\Support\Roles;
use Illuminate\Validation\ValidationException;

/**
 * İçerik fikirleri buttons shared by Genel işler and the website İçerik tab: "Onayla ve yazdır" (in the picked
 * language), "+ dil yaz", "Oku" (reader), the article's image, "WordPress'e taslak gönder" and the post's date.
 */
trait ActsOnContentIdeas
{
    /** @var array<int|string, string> content idea id => language picked before writing */
    public array $languages = [];

    /** Content idea whose article is open to read. */
    public ?int $reading = null;

    /** @var array<int|string, string> sent article id => date picked for "Yayın tarihi planla" / "Tarihi değiştir" (Istanbul) */
    public array $dates = [];

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

    /**
     * "Hepsini siteye gönder" (yakup 2026-10-20): every article Claude's check passed goes to WordPress as a draft, in
     * one Admin click; reading them first is optional.
     */
    public function sendAllContent(?int $siteId = null): void
    {
        abort_unless(auth()->user()?->hasRole(Roles::ADMIN), 403);
        $this->contentStep(fn (): string => app(ContentBoard::class)->sendAll(auth()->user(), $this->contentBrandScope(), $this->contentSiteScope() ?? $siteId));
    }

    /** The brand the screen is limited to (Genel işler's brand filter). */
    protected function contentBrandScope(): ?int
    {
        return null;
    }

    /** The site the screen is limited to (the website İçerik tab). */
    protected function contentSiteScope(): ?int
    {
        return null;
    }

    /** "Sayfaya uygula": a strengthened page's new version replaces the live page (Admin click, undoable). */
    public function applyPageUpdate(int $id): void
    {
        if (! $this->ownsContentIdea($id)) {
            return;
        }
        $this->contentStep(fn (): string => app(ContentBoard::class)->applyPage($id, auth()->user()));
    }

    /** The featured image of an article before it is sent; null = "Görselsiz". */
    public function pickImage(int $id, ?int $mediaId = null): void
    {
        if (! $this->ownsContentIdea($id)) {
            return;
        }
        $this->contentStep(fn (): string => app(ContentBoard::class)->setImage($id, $mediaId));
    }

    /** "Yayın tarihi planla" (draft) or "Tarihi değiştir" (published); admin only, logged, undoable. */
    public function changeContentDate(int $id): void
    {
        if (! $this->ownsContentIdea($id)) {
            return;
        }
        $date = trim((string) ($this->dates[$id] ?? ''));
        if ($date === '') {
            $this->message = 'Önce tarihi seç.';

            return;
        }
        $this->contentStep(fn (): string => app(ContentBoard::class)->changeDate($id, $date, auth()->user()));
        unset($this->dates[$id]);
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
