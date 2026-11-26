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
    'profile_id', 'member_id', 'folder_id', 'category', 'insurance_policy_id', 'title', 'original_filename',
    'storage_path', 'mime_type', 'size_bytes', 'expires_on', 'visible_to_professional', 'shared_with_caregivers', 'created_by_member_id', 'created_by_user_id',
    'health_appointment_id', 'health_followup_id',
])]
class Document extends Model
{
    use BelongsToProfile, HasDocumentVisibility, HasUuids, \App\Models\Concerns\HasActorUser;

    protected function casts(): array
    {
        return [
            'category' => DocumentCategory::class,
            'expires_on' => 'date',
            'size_bytes' => 'integer',
            'visible_to_professional' => 'boolean',
            'shared_with_caregivers' => 'boolean',
        ];
    }

    public function member(): BelongsTo
    {
        return $this->belongsTo(ProfileMember::class, 'member_id');
    }

    public function folder(): BelongsTo
    {
        return $this->belongsTo(DocumentFolder::class, 'folder_id');
    }

    public function insurancePolicy(): BelongsTo
    {
        return $this->belongsTo(InsurancePolicy::class);
    }

    /** O acompanhamento de saúde a que o documento foi ligado (vazio para quem não vê a saúde: o escopo devolve nada). */
    public function healthFollowup(): BelongsTo
    {
        return $this->belongsTo(HealthFollowup::class, 'health_followup_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(ProfileMember::class, 'created_by_member_id');
    }
}
