<?php

namespace App\Models;

use App\Enums\CautionMark;
use App\Models\Concerns\BelongsToProfile;
use App\Models\Concerns\IsPersonalHealthData;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** O que ficou decidido sobre um exercício da pessoa diante de uma limitação. Cita o treino dela, por isso é só dela. */
#[Fillable(['profile_id', 'member_id', 'limitation_id', 'exercise_id', 'mark'])]
class GymExerciseCaution extends Model
{
    use BelongsToProfile, HasUuids, IsPersonalHealthData;

    protected function casts(): array
    {
        return ['mark' => CautionMark::class];
    }

    public function exercise(): BelongsTo
    {
        return $this->belongsTo(GymExercise::class, 'exercise_id');
    }
}
