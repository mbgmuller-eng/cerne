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

/** Um resultado de um laudo de valores: o que foi medido, o valor, a unidade e a referência como impressos. */
#[Fillable(['profile_id', 'member_id', 'report_id', 'name', 'value', 'unit', 'reference', 'position', 'created_by_member_id', 'created_by_user_id', 'changed_by_user_id'])]
class HealthExamValue extends Model
{
    use BelongsToProfile, HasActorUser, HasUuids, IsCoupleHealthData;

    protected function casts(): array
    {
        return ['name' => Sensitive::class, 'value' => Sensitive::class, 'unit' => Sensitive::class, 'reference' => Sensitive::class, 'position' => 'integer'];
    }

    public function report(): BelongsTo
    {
        return $this->belongsTo(HealthExamReport::class, 'report_id');
    }
}
