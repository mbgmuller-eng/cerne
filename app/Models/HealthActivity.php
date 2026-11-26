<?php

namespace App\Models;

use App\Casts\Sensitive;
use App\Enums\HealthActivityKind;
use App\Models\Concerns\BelongsToProfile;
use App\Models\Concerns\HasActorUser;
use App\Models\Concerns\IsCoupleHealthData;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Uma atividade do programa (fisioterapia, pilates, academia...): frequência, exercícios cadastrados à mão e presenças com nota de dor.
 * O Cerne não escolhe exercício para um problema de saúde: quem orienta é o profissional.
 */
#[Fillable(['profile_id', 'member_id', 'followup_id', 'kind', 'name', 'per_week', 'total_sessions', 'gym_plan_id', 'is_active', 'created_by_member_id', 'created_by_user_id', 'changed_by_user_id'])]
class HealthActivity extends Model
{
    use BelongsToProfile, HasActorUser, HasUuids, IsCoupleHealthData;

    protected function casts(): array
    {
        return ['kind' => HealthActivityKind::class, 'name' => Sensitive::class, 'per_week' => 'integer', 'total_sessions' => 'integer', 'is_active' => 'boolean'];
    }

    public function followup(): BelongsTo
    {
        return $this->belongsTo(HealthFollowup::class, 'followup_id');
    }

    public function exercises(): HasMany
    {
        return $this->hasMany(HealthActivityExercise::class, 'activity_id')->orderBy('position')->orderBy('created_at');
    }

    public function sessions(): HasMany
    {
        return $this->hasMany(HealthActivitySession::class, 'activity_id');
    }

    /** A fase da Academia ligada. Como é dado só do dono (PersonalHealthScope), para qualquer outra pessoa vem nula. */
    public function gymPlan(): BelongsTo
    {
        return $this->belongsTo(GymPlan::class, 'gym_plan_id');
    }
}
