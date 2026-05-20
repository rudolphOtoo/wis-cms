<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class BroadcastMessage extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $subjectLine,
        public string $messageBody,
        public string $recipientName,
        public string $branchName,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->subjectLine);
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.broadcast',
            with: [
                'subjectLine' => $this->subjectLine,
                'messageBody' => $this->messageBody,
                'recipientName' => $this->recipientName,
                'branchName' => $this->branchName,
            ],
        );
    }
}
