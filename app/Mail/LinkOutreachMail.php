<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/** A Bağlantı masası e-mail: plain text, written for one source, sent with the operator's click. */
final class LinkOutreachMail extends Mailable
{
    public function __construct(public string $mailSubject, public string $text) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->mailSubject);
    }

    public function content(): Content
    {
        return new Content(htmlString: nl2br(e($this->text)));
    }
}
