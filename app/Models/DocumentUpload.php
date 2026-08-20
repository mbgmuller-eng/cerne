<?php

namespace App\Models;

use App\Enums\DocumentType;
use App\Enums\ProcessingStatus;
use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToProfile;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

#[Fillable([
    'profile_id', 'uploaded_by_user_id', 'member_id', 'document_type',
    'original_filename', 'storage_path', 'size_bytes', 'institution_name',
    'reference_month', 'reference_year', 'processing_status',
    'records_extracted', 'extraction_summary', 'error_message',
    'processed_at', 'committed_at',
])]
class DocumentUpload extends Model
{
    use Auditable, BelongsToProfile, HasFactory, HasUuids;

    protected function casts(): array
    {
        return [
            'document_type' => DocumentType::class,
            'processing_status' => ProcessingStatus::class,
            'extraction_summary' => 'array',
            'reference_month' => 'integer',
            'reference_year' => 'integer',
            'records_extracted' => 'integer',
            'size_bytes' => 'integer',
            'processed_at' => 'datetime',
            'committed_at' => 'datetime',
        ];
    }

    public function uploadedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by_user_id');
    }

    public function member(): BelongsTo
    {
        return $this->belongsTo(ProfileMember::class, 'member_id');
    }

    public function scopeAwaitingReview(Builder $query): Builder
    {
        return $query->where('processing_status', ProcessingStatus::Completed);
    }

    /**
     * Itens extraídos aguardando confirmação.
     *
     * Vivem em `extraction_summary` e NÃO no banco como lançamentos: dado
     * financeiro extraído por IA entra depois da revisão humana, nunca
     * antes.
     *
     * @return array<int, array<string, mixed>>
     */
    public function extractedItems(): array
    {
        return $this->extraction_summary['itens'] ?? [];
    }

    public function extractionNotes(): ?string
    {
        return $this->extraction_summary['observacoes'] ?? null;
    }

    public function competenceLabel(): ?string
    {
        if ($this->reference_year === null) {
            return null;
        }

        return $this->reference_month === null
            ? (string) $this->reference_year
            : str_pad((string) $this->reference_month, 2, '0', STR_PAD_LEFT).'/'.$this->reference_year;
    }

    /** O arquivo em si, para enviar à API. */
    public function contents(): string
    {
        return Storage::disk(config('cerne.documents.disk'))->get($this->storage_path);
    }

    public function deleteFile(): void
    {
        Storage::disk(config('cerne.documents.disk'))->delete($this->storage_path);
    }

    public function isAwaitingReview(): bool
    {
        return $this->processing_status === ProcessingStatus::Completed;
    }
}
