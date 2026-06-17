<?php

namespace App\Mail;

use App\Models\Member;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class MemberWelcomeEmail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public Member $member) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'Welcome to '.config('church.name', 'Our Church'),
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.member-welcome',
            with: [
                'member' => $this->member,
                'churchName' => config('church.name'),
            ],
        );
    }
}
