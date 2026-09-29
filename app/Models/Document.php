<?php

namespace App\Models;

use App\Enums\DocumentCategory;
use App\Models\Concerns\BelongsToProfile;
use App\Models\Concerns\HasDocumentVisibility;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Um documento guardado na área de Documentos (CNH, passaporte,
 * certificado, apólice, exame...) — ver DocumentVisibilityScope pra quem
 * enxerga o quê.
 */
#[Fillable([
    'profile_id', 'member_id', 'category', 'insurance_policy_id', 'title', 'original_filename',
    'storage_path', 'mime_type', 'size_bytes', 'expires_on', 'visible_to_professional', 'created_by_member_id',
])]
class Document extends Model
{
    use BelongsToProfile, HasDocumentVisibility, HasUuids;

    protected function casts(): array
    {
        return [
            'category' => DocumentCategory::class,
            'expires_on' => 'date',
            'size_bytes' => 'integer',
            'visible_to_professional' => 'boolean',
        ];
    }

    public function member(): BelongsTo
    {
        return $this->belongsTo(ProfileMember::class, 'member_id');
    }

    public function insurancePolicy(): BelongsTo
    {
        return $this->belongsTo(InsurancePolicy::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(ProfileMember::class, 'created_by_member_id');
    }
}
