<?php

namespace App\Models;

use App\Models\Concerns\BelongsToProfile;
use App\Models\Concerns\IsCoupleHealthData;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Doença ou comorbidade — "diabetes tipo 2", "hipertensão". */
#[Fillable(['profile_id', 'member_id', 'description', 'created_by_member_id'])]
class HealthCondition extends Model
{
    use BelongsToProfile, HasUuids, IsCoupleHealthData;

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(ProfileMember::class, 'created_by_member_id');
    }
}
