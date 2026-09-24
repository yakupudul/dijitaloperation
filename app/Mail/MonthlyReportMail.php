<?php

namespace App\Mail;

use App\Models\MonthlyReport;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/** The client's monthly report: a short summary and the signed link to the full report. */
class MonthlyReportMail extends Mailable
{
    use Queueable;

    public function __construct(public readonly MonthlyReport $report, public readonly string $url) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: ($this->report->brand?->name ?? 'Marka').' · '.$this->report->month.' aylık dijital rapor');
    }

    public function content(): Content
    {
        return new Content(markdown: 'emails.reports.monthly', with: [
            'brand' => $this->report->brand?->name,
            'month' => $this->report->month,
            'summary' => (string) data_get($this->report->commentary, 'summary', ''),
            'url' => $this->url,
            'days' => (int) config('moxdop-reports.client_link_days', 60),
        ]);
    }
}
