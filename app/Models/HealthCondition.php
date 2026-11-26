<?php

namespace App\Models;

use App\Casts\Sensitive;
use App\Enums\HealthConditionStatus;
use App\Models\Concerns\BelongsToProfile;
use App\Models\Concerns\HasActorUser;
use App\Models\Concerns\IsCoupleHealthData;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Um problema de saúde da ficha, com a situação e de onde veio (consulta, exame, acompanhamento ou o passado, informado pela pessoa). O Cerne
 * nunca cria um problema a partir de um exame: quem adiciona é a pessoa.
 */
#[Fillable([
    'profile_id', 'member_id', 'description', 'status', 'since_year', 'exercise_care', 'followup_id', 'appointment_id', 'exam_report_id',
    'created_by_member_id', 'created_by_user_id',
])]
class HealthCondition extends Model
{
    use BelongsToProfile, HasActorUser, HasUuids, IsCoupleHealthData;

    protected $attributes = ['status' => 'active', 'exercise_care' => false];

    protected function casts(): array
    {
        return ['description' => Sensitive::class, 'status' => HealthConditionStatus::class, 'since_year' => 'integer', 'exercise_care' => 'boolean'];
    }

    public function isCurrent(): bool
    {
        return $this->status !== HealthConditionStatus::Resolved;
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(ProfileMember::class, 'created_by_member_id');
    }

    public function followup(): BelongsTo
    {
        return $this->belongsTo(HealthFollowup::class, 'followup_id');
    }

    public function appointment(): BelongsTo
    {
        return $this->belongsTo(HealthAppointment::class, 'appointment_id');
    }

    public function examReport(): BelongsTo
    {
        return $this->belongsTo(HealthExamReport::class, 'exam_report_id');
    }
}
