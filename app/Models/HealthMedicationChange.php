<?php

namespace App\Models;

use App\Enums\HealthMedicationChangeType;
use App\Models\Concerns\BelongsToProfile;
use App\Models\Concerns\IsCoupleHealthData;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * UMA entrada da linha do tempo de um remédio — "10/09, dose de 10mg
 * pra 20mg, registrado por Ana". Nunca editada nem apagada depois de
 * criada (é histórico); só GymSetLog e InsurancePolicyRenewal têm o
 * mesmo formato de "log que só cresce" no resto do app.
 */
#[Fillable([
    'profile_id', 'member_id', 'medication_id', 'change_type',
    'old_value', 'new_value', 'note', 'changed_by_member_id',
])]
class HealthMedicationChange extends Model
{
    use BelongsToProfile, HasUuids, IsCoupleHealthData;

    protected function casts(): array
    {
        return [
            'change_type' => HealthMedicationChangeType::class,
        ];
    }

    public function medication(): BelongsTo
    {
        return $this->belongsTo(HealthMedication::class, 'medication_id');
    }

    public function changedBy(): BelongsTo
    {
        return $this->belongsTo(ProfileMember::class, 'changed_by_member_id');
    }
}
