<?php

namespace App\Models;

use App\Casts\Sensitive;
use App\Models\Concerns\BelongsToProfile;
use App\Models\Concerns\IsCoupleHealthData;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['profile_id', 'member_id', 'description', 'created_by_member_id', 'created_by_user_id'])]
class HealthAllergy extends Model
{
    protected function casts(): array
    {
        return ['description' => Sensitive::class];
    }

    use BelongsToProfile, HasUuids, IsCoupleHealthData, \App\Models\Concerns\HasActorUser;

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(ProfileMember::class, 'created_by_member_id');
    }
}
