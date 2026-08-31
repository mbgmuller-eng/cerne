<?php

namespace App\Mail;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class ConsultantLinkRequestMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public User $consultant,
        public string $link,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: "{$this->consultant->name} quer se vincular à sua conta no Cerne",
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'mail.consultant-link-request',
            with: ['consultantName' => $this->consultant->name, 'link' => $this->link],
        );
    }
}
