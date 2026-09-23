<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * Internal weekly digest to operators: the top jobs across SEO Görevleri and the advisor channels.
 * Titles only, no client metrics; the operator opens the app for details.
 */
class AdvisorWeeklyDigestMail extends Mailable
{
    use Queueable;

    /** @param list<array<string, mixed>> $items */
    public function __construct(public readonly array $items) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Bu haftanın en önemli '.count($this->items).' işi');
    }

    public function content(): Content
    {
        return new Content(markdown: 'emails.advisor.weekly-digest', with: ['items' => $this->items, 'url' => url('/')]);
    }
}
