<?php

namespace App\Models;

use App\Enums\ProcessingStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Arquivo de um lead (a simulação em PDF de uma cotação ou um documento do contato). Fica no disco privado e só abre
 * para o dono do lead (ver LeadAttachmentController); o serviço apaga o arquivo junto com o registro.
 */
#[Fillable([
    'lead_id', 'lead_quote_id', 'original_filename', 'storage_path', 'mime_type', 'size_bytes', 'uploaded_by_user_id',
    'extraction_status', 'extraction_requested_at', 'extraction_summary', 'extraction_error', 'extraction_applied_at',
])]
class LeadAttachment extends Model
{
    use HasUuids;

    protected function casts(): array
    {
        return [
            'extraction_status' => ProcessingStatus::class,
            'extraction_requested_at' => 'datetime',
            'extraction_summary' => 'array',
            'extraction_applied_at' => 'datetime',
        ];
    }

    public function isPdf(): bool
    {
        return $this->mime_type === 'application/pdf';
    }

    /** A leitura terminou e ainda não foi usada para cadastrar uma cotação. */
    public function awaitingReview(): bool
    {
        return $this->extraction_status === ProcessingStatus::Completed && $this->extraction_applied_at === null;
    }

    public function lead(): BelongsTo
    {
        return $this->belongsTo(Lead::class);
    }

    public function quote(): BelongsTo
    {
        return $this->belongsTo(LeadQuote::class, 'lead_quote_id');
    }

    public function sizeLabel(): string
    {
        return $this->size_bytes >= 1048576
            ? number_format($this->size_bytes / 1048576, 1, ',', '.').' MB'
            : max(1, (int) round($this->size_bytes / 1024)).' KB';
    }
}
