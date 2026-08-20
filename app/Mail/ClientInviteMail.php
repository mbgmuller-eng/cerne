<?php

namespace App\Mail;

use App\Models\ConsultantInvite;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class ClientInviteMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public ConsultantInvite $invite,
        public string $link,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: "{$this->invite->consultant->name} convidou você para o Cerne",
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'mail.client-invite',
            with: [
                'clientName' => $this->invite->client_name,
                'consultantName' => $this->invite->consultant->name,
                'link' => $this->link,
                'expiresAt' => $this->invite->expires_at,
            ],
        );
    }
}
