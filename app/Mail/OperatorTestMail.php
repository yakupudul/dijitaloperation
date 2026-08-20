<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

final class OperatorTestMail extends Mailable
{
    public function envelope(): Envelope
    {
        return new Envelope(
            subject: __('operator.mail.test_subject'),
        );
    }

    public function content(): Content
    {
        return new Content(
            htmlString: '<p>'.e(__('operator.mail.test_body')).'</p>',
        );
    }
}
