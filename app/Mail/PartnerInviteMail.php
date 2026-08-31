<?php

namespace App\Mail;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class PartnerInviteMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public User $inviter,
        public string $partnerName,
        public string $link,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: "{$this->inviter->name} convidou você para o Cerne",
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'mail.partner-invite',
            with: [
                'partnerName' => $this->partnerName,
                'inviterName' => $this->inviter->name,
                'link' => $this->link,
            ],
        );
    }
}
