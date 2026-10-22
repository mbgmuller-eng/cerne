<?php

namespace App\Mail;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class CarePersonInviteMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public User $inviter,
        public string $personName,
        public string $link,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: "{$this->inviter->name} criou o seu espaço de saúde no Cerne");
    }

    public function content(): Content
    {
        return new Content(markdown: 'mail.care-person-invite', with: [
            'personName' => $this->personName,
            'inviterName' => $this->inviter->name,
            'link' => $this->link,
        ]);
    }
}
