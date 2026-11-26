<?php

namespace App\Models;

use App\Casts\Sensitive;
use App\Models\Concerns\BelongsToProfile;
use App\Models\Concerns\HasActorUser;
use App\Models\Concerns\IsCoupleHealthData;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Rotina preventiva (check-up geral, dentista...): de quanto em quanto tempo, a última vez e a próxima. Ver HealthRoutineService. */
#[Fillable(['profile_id', 'member_id', 'name', 'interval_months', 'last_done_on', 'next_due_on', 'is_active', 'created_by_member_id', 'created_by_user_id', 'changed_by_user_id'])]
class HealthRoutine extends Model
{
    use BelongsToProfile, HasActorUser, HasUuids, IsCoupleHealthData;

    /** meses => como aparece na tela */
    public const INTERVALS = [3 => 'a cada 3 meses', 6 => 'a cada 6 meses', 12 => 'todo ano', 24 => 'a cada 2 anos', 36 => 'a cada 3 anos'];

    protected function casts(): array
    {
        return [
            'name' => Sensitive::class,
            'interval_months' => 'integer',
            'last_done_on' => 'date',
            'next_due_on' => 'date',
            'is_active' => 'boolean',
        ];
    }

    public function appointments(): HasMany
    {
        return $this->hasMany(HealthAppointment::class, 'routine_id');
    }

    public function requests(): HasMany
    {
        return $this->hasMany(HealthExamRequest::class, 'routine_id');
    }

    public function frequencyLabel(): string
    {
        return self::INTERVALS[$this->interval_months] ?? "a cada {$this->interval_months} meses";
    }
}
