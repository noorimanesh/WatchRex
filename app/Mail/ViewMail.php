<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/** Simple HTML mailable used for alerts and status-page notices. */
class ViewMail extends Mailable
{
    use Queueable;

    public function __construct(public string $subjectLine, public string $template, public array $payload = []) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->subjectLine);
    }

    public function content(): Content
    {
        return new Content(view: $this->template, with: $this->payload);
    }
}
