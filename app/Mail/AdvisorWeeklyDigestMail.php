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

    /**
     * @param  list<array<string, mixed>>  $items
     * @param  list<array{title: string, asset: string, brand: string, message: string, severity: string}>  $alerts  open asset alerts
     */
    public function __construct(public readonly array $items, public readonly array $alerts = []) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Bu haftanın en önemli '.count($this->items).' işi');
    }

    public function content(): Content
    {
        return new Content(markdown: 'emails.advisor.weekly-digest', with: ['items' => $this->items, 'alerts' => $this->alerts, 'url' => url('/')]);
    }
}
