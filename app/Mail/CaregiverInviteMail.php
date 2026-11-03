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

    /**
     * @param  bool  $health  o convite inclui a saúde
     * @param  bool  $finance  o convite inclui as finanças (só leitura)
     * @param  bool  $assisted  o consultor liberou em nome da pessoa (convite assistido), com a autorização dela
     */
    public function __construct(
        public User $inviter,
        public string $personName,
        public string $caregiverName,
        public CaregiverAccess $access,
        public string $link,
        public bool $selfFollow = false,
        public bool $health = true,
        public bool $finance = false,
        public bool $assisted = false,
    ) {}

    public function envelope(): Envelope
    {
        $assunto = match (true) {
            $this->assisted => "{$this->inviter->name} liberou o seu acesso a {$this->personName} no Cerne",
            $this->selfFollow => "{$this->inviter->name} convidou você para acompanhar o Cerne",
            default => "{$this->inviter->name} convidou você para acompanhar {$this->personName} no Cerne",
        };

        return new Envelope(subject: $assunto);
    }

    public function content(): Content
    {
        return new Content(markdown: 'mail.caregiver-invite', with: [
            'caregiverName' => $this->caregiverName,
            'inviterName' => $this->inviter->name,
            'personName' => $this->personName,
            'access' => $this->access,
            'link' => $this->link,
            'selfFollow' => $this->selfFollow,
            'health' => $this->health,
            'finance' => $this->finance,
            'assisted' => $this->assisted,
        ]);
    }
}