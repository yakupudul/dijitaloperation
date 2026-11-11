<?php

namespace App\Livewire\Operator\Settings;

use App\Services\Brain\Playbooks;
use App\Support\Roles;
use Illuminate\Contracts\View\View;
use InvalidArgumentException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Ayarlar › Kitaplar (Admin, Marka Düzeni 2.4): the master and sector books Claude loads before it looks at a brand.
 * The operator reads and edits a book's text, takes the last edit back, and adds or removes lessons. Claude itself can
 * only add a lesson (from a correction or a measured result) or write a missing sector book.
 */
#[Layout('operator.layouts.app')]
#[Title('Kitaplar')]
final class BooksPage extends Component
{
    #[Url(as: 'kitap')]
    public string $key = 'seo';

    public string $body = '';

    public string $lesson = '';

    public bool $editing = false;

    public string $message = '';

    public function mount(Playbooks $books): void
    {
        $this->authorizeAdmin();
        if ($books->get($this->key) === null) {
            $this->key = 'seo';
        }
    }

    public function open(string $key, Playbooks $books): void
    {
        if ($books->get($key) !== null) {
            $this->key = $key;
            $this->editing = false;
            $this->message = '';
            $this->lesson = '';
        }
    }

    public function edit(Playbooks $books): void
    {
        $this->authorizeAdmin();
        $this->body = (string) ($books->get($this->key)['body'] ?? '');
        $this->editing = true;
        $this->message = '';
    }

    public function save(Playbooks $books): void
    {
        $this->authorizeAdmin();
        try {
            $books->save($this->key, $this->body, (string) auth()->user()?->name);
            $this->editing = false;
            $this->message = 'Kitap kaydedildi. Claude bir sonraki bakışında bu metni okur.';
        } catch (InvalidArgumentException $error) {
            $this->message = $error->getMessage();
        }
    }

    public function undo(Playbooks $books): void
    {
        $this->authorizeAdmin();
        $books->undo($this->key);
        $this->editing = false;
        $this->message = 'Bir önceki metne dönüldü.';
    }

    public function addLesson(Playbooks $books): void
    {
        $this->authorizeAdmin();
        try {
            $books->lesson($this->key, $this->lesson, 'duzeltme', 'Panelden yazıldı.', (string) auth()->user()?->name);
            $this->lesson = '';
            $this->message = 'Ders eklendi.';
        } catch (InvalidArgumentException $error) {
            $this->message = $error->getMessage();
        }
    }

    public function dropLesson(string $id, Playbooks $books): void
    {
        $this->authorizeAdmin();
        $books->dropLesson($this->key, $id);
        $this->message = 'Ders kaldırıldı.';
    }

    public function render(Playbooks $books): View
    {
        $all = $books->all();

        return view('livewire.operator.settings.books', ['books' => $all, 'book' => collect($all)->firstWhere('key', $this->key) ?? $all[0], 'max' => Playbooks::MAX_BODY]);
    }

    private function authorizeAdmin(): void
    {
        abort_unless(auth()->user()?->is_active && auth()->user()?->hasRole(Roles::ADMIN), 403);
    }
}
