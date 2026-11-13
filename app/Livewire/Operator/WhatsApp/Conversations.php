<?php

namespace App\Livewire\Operator\WhatsApp;

use App\Services\WhatsApp\ConversationArchive;
use App\Services\WhatsApp\WhatsAppConnection;
use Illuminate\Contracts\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * WhatsApp konuşmaları (yakup, 2026-11-13): every WhatsApp chat from every source on one screen, searchable in the message
 * text ("fatura istem" → who said they want no invoice) and readable in full. Read only; admins only, like the inbox.
 */
#[Layout('operator.layouts.app')]
#[Title('WhatsApp konuşmaları')]
final class Conversations extends Component
{
    #[Url(except: '')]
    public string $q = '';

    #[Url(except: '')]
    public string $source = '';

    #[Url(except: null)]
    public ?int $conversation = null;

    #[Url(except: null)]
    public ?int $around = null;

    public ?int $before = null;

    public function boot(WhatsAppConnection $connection): void
    {
        $connection->authorize(auth()->user());
    }

    public function updatedQ(): void
    {
        $this->q = mb_substr($this->q, 0, 200);
    }

    public function updatedSource(): void
    {
        if ($this->source !== '' && preg_match('/^(live|line:\d+)$/', $this->source) !== 1) {
            $this->source = '';
        }
    }

    public function open(int $conversation, ?int $around = null): void
    {
        $this->conversation = $conversation;
        $this->around = $around;
        $this->before = null;
    }

    public function older(int $before): void
    {
        $this->around = null;
        $this->before = $before;
    }

    public function close(): void
    {
        $this->conversation = null;
        $this->around = null;
        $this->before = null;
    }

    public function render(ConversationArchive $archive): View
    {
        $this->updatedSource();
        $source = $this->source !== '' ? $this->source : null;
        $term = trim($this->q);
        $searching = ConversationArchive::words($term) !== [];

        return view('livewire.operator.whatsapp.conversations-archive', [
            'sources' => $archive->sources(),
            'people' => $archive->conversations($term, $source, $searching ? 20 : 100),
            'result' => $searching ? $archive->search($term, ['source' => $source, 'limit' => 100, 'seconds' => 10.0]) : null,
            'chat' => $this->conversation !== null ? $archive->read($this->conversation, $this->around, $this->before, 100) : null,
            'words' => ConversationArchive::words($term),
        ]);
    }
}
