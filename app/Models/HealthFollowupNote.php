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

/** Anotação da linha do tempo de um acompanhamento, com nota de dor opcional (0 a 10). */
#[Fillable(['profile_id', 'member_id', 'followup_id', 'noted_on', 'text', 'pain', 'created_by_member_id', 'created_by_user_id', 'changed_by_user_id'])]
class HealthFollowupNote extends Model
{
    use BelongsToProfile, HasActorUser, HasUuids, IsCoupleHealthData;

    protected function casts(): array
    {
        return ['noted_on' => 'date', 'text' => Sensitive::class, 'pain' => 'integer'];
    }

    public function followup(): BelongsTo
    {
        return $this->belongsTo(HealthFollowup::class, 'followup_id');
    }
}
