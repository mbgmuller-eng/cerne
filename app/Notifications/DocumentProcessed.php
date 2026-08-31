<?php

namespace App\Notifications;

use App\Enums\ProcessingStatus;
use App\Models\DocumentUpload;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use NotificationChannels\WebPush\WebPushChannel;
use NotificationChannels\WebPush\WebPushMessage;

/**
 * Avisa o dono do envio quando a leitura por IA termina — sucesso ou falha.
 *
 * Carrega dado já resolvido, não o Eloquent model: DocumentUpload usa
 * BelongsToProfile, cujo escopo falha FECHADO sem ProfileContext ativo — a
 * situação normal de um worker de fila real (QUEUE_CONNECTION=database em
 * produção). Guardar o model faria o worker reidratar tudo como null.
 */
class DocumentProcessed extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public string $documentId,
        public string $filename,
        public ProcessingStatus $status,
        public ?string $errorMessage = null,
    ) {}

    public static function forDocument(DocumentUpload $documento): self
    {
        return new self($documento->id, $documento->original_filename, $documento->processing_status, $documento->error_message);
    }

    /** @return array<int, string> */
    public function via(object $notifiable): array
    {
        $channels = ['database'];

        if ($notifiable->notify_email_enabled) {
            $channels[] = 'mail';
        }

        if ($notifiable->notify_push_enabled) {
            $channels[] = WebPushChannel::class;
        }

        return $channels;
    }

    public function toMail(object $notifiable): MailMessage
    {
        $sucesso = $this->status === ProcessingStatus::Completed;

        return (new MailMessage)
            ->subject($sucesso ? 'Importação concluída' : 'Falha na importação')
            ->markdown('mail.document-processed', [
                'recipientName' => $notifiable->name,
                'sucesso' => $sucesso,
                'filename' => $this->filename,
                'errorMessage' => $this->errorMessage,
                'url' => route('documents.index'),
            ]);
    }

    /** @return array<string, mixed> */
    public function toArray(object $notifiable): array
    {
        return [
            'kind' => 'document_processed',
            'document_upload_id' => $this->documentId,
            'title' => $this->filename,
            'status' => $this->status->value,
            'error_message' => $this->errorMessage,
        ];
    }

    public function toWebPush(object $notifiable, self $notification): WebPushMessage
    {
        $sucesso = $this->status === ProcessingStatus::Completed;

        return (new WebPushMessage)
            ->title($sucesso ? 'Importação concluída' : 'Falha na importação')
            ->body($this->filename)
            ->data(['url' => route('documents.index')]);
    }
}
