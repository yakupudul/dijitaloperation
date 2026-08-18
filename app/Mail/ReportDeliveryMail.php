<?php

namespace App\Mail;

use App\Models\ReportDelivery;
use App\Models\ReportSnapshot;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Minimal transactional report delivery email — no sensitive metrics.
 */
class ReportDeliveryMail extends Mailable
{
    use Queueable;
    use SerializesModels;

    public function __construct(
        public readonly ReportDelivery $delivery,
        public readonly ReportSnapshot $snapshot,
        public readonly string $shareLocatorUrl,
    ) {}

    public function envelope(): Envelope
    {
        $locale = $this->delivery->locale ?: 'en';
        $subject = $locale === 'tr'
            ? 'Rapor hazır: '.$this->snapshot->brand_name_snapshot
            : 'Report ready: '.$this->snapshot->brand_name_snapshot;

        return new Envelope(subject: $subject);
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'emails.reports.delivery',
            with: [
                'brandName' => (string) $this->snapshot->brand_name_snapshot,
                'title' => (string) $this->snapshot->title_snapshot,
                'periodStart' => $this->snapshot->period_start?->toDateString(),
                'periodEnd' => $this->snapshot->period_end?->toDateString(),
                'shareUrl' => $this->shareLocatorUrl,
                'expiresNotice' => true,
            ],
        );
    }
}
