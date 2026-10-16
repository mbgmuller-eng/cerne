<?php

namespace App\Mail;

use App\Services\EngagementPlanReport;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Plano de acompanhamento em PDF, enviado ao cliente quando o consultor encerra o vínculo e decide entregá-lo. Guarda só ids e
 * nomes: o PDF é gerado no momento do envio, direto pelo perfil e pelo consultor (a fila não tem usuário logado).
 */
class EngagementPlanMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $profileId,
        public string $consultantId,
        public string $consultantName,
        public string $profileName,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: "Seu plano de acompanhamento com {$this->consultantName}");
    }

    public function content(): Content
    {
        return new Content(markdown: 'mail.engagement-plan', with: ['consultantName' => $this->consultantName]);
    }

    /** @return array<int, Attachment> */
    public function attachments(): array
    {
        $relatorio = app(EngagementPlanReport::class);

        return [
            Attachment::fromData(fn () => $relatorio->pdf($this->profileId, $this->consultantId), $relatorio->fileName($this->profileName))
                ->withMime('application/pdf'),
        ];
    }
}
