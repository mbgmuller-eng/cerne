<?php

namespace App\Models;

use App\Casts\Sensitive;
use App\Models\Concerns\BelongsToProfile;
use App\Models\Concerns\HasActorUser;
use App\Models\Concerns\IsCoupleHealthData;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Exercício cadastrado à mão numa atividade do programa ("3 × 12", "5 × 20 s"). */
#[Fillable(['profile_id', 'member_id', 'activity_id', 'name', 'dose', 'note', 'position', 'created_by_member_id', 'created_by_user_id', 'changed_by_user_id'])]
class HealthActivityExercise extends Model
{
    use BelongsToProfile, HasActorUser, HasUuids, IsCoupleHealthData;

    protected function casts(): array
    {
        return ['name' => Sensitive::class, 'dose' => Sensitive::class, 'note' => Sensitive::class, 'position' => 'integer'];
    }

    public function activity(): BelongsTo
    {
        return $this->belongsTo(HealthActivity::class, 'activity_id');
    }
}
