<?php

namespace App\Mail;

use App\Enums\CaregiverAccess;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class CaregiverInviteMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public User $inviter,
        public string $personName,
        public string $caregiverName,
        public CaregiverAccess $access,
        public string $link,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: "{$this->inviter->name} convidou você para acompanhar {$this->personName} no Cerne");
    }

    public function content(): Content
    {
        return new Content(markdown: 'mail.caregiver-invite', with: [
            'caregiverName' => $this->caregiverName,
            'inviterName' => $this->inviter->name,
            'personName' => $this->personName,
            'access' => $this->access,
            'link' => $this->link,
        ]);
    }
}
