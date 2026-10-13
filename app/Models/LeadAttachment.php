<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Arquivo de um lead (a simulação em PDF de uma cotação ou um documento do contato). Fica no disco privado e só abre
 * para o dono do lead (ver LeadAttachmentController); o serviço apaga o arquivo junto com o registro.
 */
#[Fillable(['lead_id', 'lead_quote_id', 'original_filename', 'storage_path', 'mime_type', 'size_bytes', 'uploaded_by_user_id'])]
class LeadAttachment extends Model
{
    use HasUuids;

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
