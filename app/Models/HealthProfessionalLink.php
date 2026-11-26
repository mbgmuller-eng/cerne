<?php

namespace App\Models;

use App\Models\Concerns\BelongsToProfile;
use App\Models\Concerns\HasActorUser;
use App\Models\Concerns\IsCoupleHealthData;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** "Este profissional atende esta pessoa." É por aqui que se decide quem vê o profissional. */
#[Fillable(['profile_id', 'professional_id', 'member_id', 'created_by_member_id', 'created_by_user_id', 'changed_by_user_id'])]
class HealthProfessionalLink extends Model
{
    use BelongsToProfile, HasActorUser, HasUuids, IsCoupleHealthData;

    public function professional(): BelongsTo
    {
        return $this->belongsTo(HealthProfessional::class, 'professional_id');
    }
}
