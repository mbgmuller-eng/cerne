<?php

namespace App\Mail;

use App\Models\ProfessionalInvite;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class ProfessionalInviteMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public ProfessionalInvite $invite,
        public string $link,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: "Sua conta de {$this->invite->role->label()} no Cerne está pronta",
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'mail.professional-invite',
            with: [
                'name' => $this->invite->name,
                'roleLabel' => $this->invite->role->label(),
                'link' => $this->link,
                'expiresAt' => $this->invite->expires_at,
            ],
        );
    }
}
