<?php

namespace App\Mail;

use App\Models\DataExport;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Support\Facades\URL;

/** Avisa que a cópia dos dados está pronta. O link é assinado, expira com o arquivo e só abre para quem está logado como o dono. */
class DataExportReadyMail extends Mailable
{
    public function __construct(public DataExport $export) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Cerne: a cópia dos seus dados está pronta');
    }

    public function content(): Content
    {
        $url = URL::temporarySignedRoute('privacy.export.download', $this->export->expires_at, ['export' => $this->export->id]);

        return new Content(markdown: 'mail.data-export-ready', with: ['url' => $url, 'expiraEm' => $this->export->expires_at]);
    }
}
