<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class SecurityAlert extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    /** @param array<string,string> $facts */
    public function __construct(public string $headline, public array $facts, public string $auditUrl)
    {
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: '['.config('app.name').'] Security alert: '.$this->headline);
    }

    public function content(): Content
    {
        return new Content(text: 'mail.security-alert');
    }
}
