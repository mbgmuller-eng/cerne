<?php

namespace App\Models;

use App\Models\Concerns\BelongsToProfile;
use App\Models\Concerns\IsCoupleHealthData;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'profile_id', 'member_id', 'name', 'dose', 'schedule', 'reason', 'prescriber',
    'started_on', 'ended_on', 'is_active', 'created_by_member_id',
])]
class HealthMedication extends Model
{
    use BelongsToProfile, HasUuids, IsCoupleHealthData;

    protected function casts(): array
    {
        return [
            'started_on' => 'date',
            'ended_on' => 'date',
            'is_active' => 'boolean',
        ];
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(ProfileMember::class, 'created_by_member_id');
    }

    /**
     * Mais recente primeiro — é uma linha do tempo, lida de cima pra baixo.
     * Desempate por `id` (UUID v7, ordenado por tempo em milissegundos):
     * `created_at` só tem precisão de segundo, então duas mudanças na
     * mesma ação (ex.: suspender e retomar em sequência no mesmo teste)
     * podem empatar nela e sair em ordem indeterminada sem isto.
     */
    public function changes(): HasMany
    {
        return $this->hasMany(HealthMedicationChange::class, 'medication_id')->latest('created_at')->latest('id');
    }
}
