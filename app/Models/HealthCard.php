<?php

namespace App\Models;

use App\Models\Concerns\BelongsToProfile;
use App\Models\Concerns\IsCoupleHealthData;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Cabeçalho da ficha de saúde de UMA pessoa — hoje só o tipo sanguíneo. */
#[Fillable(['profile_id', 'member_id', 'blood_type', 'updated_by_member_id'])]
class HealthCard extends Model
{
    use BelongsToProfile, HasUuids, IsCoupleHealthData;

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(ProfileMember::class, 'updated_by_member_id');
    }
}
