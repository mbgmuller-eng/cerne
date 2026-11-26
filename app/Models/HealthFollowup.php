<?php

namespace App\Models;

use App\Casts\Sensitive;
use App\Enums\HealthFollowupStatus;
use App\Models\Concerns\BelongsToProfile;
use App\Models\Concerns\HasActorUser;
use App\Models\Concerns\IsCoupleHealthData;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Acompanhamento de um problema de saúde: a linha do tempo de consultas, exames, documentos e anotações, e o programa de atividades que um
 * profissional orientou. A ligação com os compromissos da Agenda é muitos para muitos (a consulta do check-up pode abrir um problema).
 */
#[Fillable(['profile_id', 'member_id', 'title', 'status', 'started_on', 'resolved_on', 'guided_by', 'guided_on', 'avoid', 'created_by_member_id', 'created_by_user_id', 'changed_by_user_id'])]
class HealthFollowup extends Model
{
    use BelongsToProfile, HasActorUser, HasUuids, IsCoupleHealthData;

    protected function casts(): array
    {
        return [
            'title' => Sensitive::class,
            'status' => HealthFollowupStatus::class,
            'started_on' => 'date',
            'resolved_on' => 'date',
            'guided_by' => Sensitive::class,
            'guided_on' => 'date',
            'avoid' => Sensitive::class,
        ];
    }

    public function appointments(): BelongsToMany
    {
        return $this->belongsToMany(HealthAppointment::class, 'health_followup_appointment', 'followup_id', 'appointment_id');
    }

    public function notes(): HasMany
    {
        return $this->hasMany(HealthFollowupNote::class, 'followup_id');
    }

    public function requests(): HasMany
    {
        return $this->hasMany(HealthExamRequest::class, 'followup_id');
    }

    public function activities(): HasMany
    {
        return $this->hasMany(HealthActivity::class, 'followup_id');
    }

    public function documents(): HasMany
    {
        return $this->hasMany(Document::class, 'health_followup_id');
    }

    public function isResolved(): bool
    {
        return $this->status === HealthFollowupStatus::Resolved;
    }
}
