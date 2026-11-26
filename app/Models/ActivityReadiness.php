<?php

namespace App\Models;

use App\Casts\Sensitive;
use App\Casts\SensitiveArray;
use App\Models\Concerns\BelongsToProfile;
use App\Models\Concerns\IsPersonalHealthData;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/** As respostas de prontidão da pessoa antes de um plano de atividades. Só ela vê. */
#[Fillable(['profile_id', 'member_id', 'answers', 'clearance', 'cleared_by', 'cleared_on', 'answered_on'])]
class ActivityReadiness extends Model
{
    use BelongsToProfile, HasUuids, IsPersonalHealthData;

    protected $table = 'activity_readiness';

    protected function casts(): array
    {
        return ['answers' => SensitiveArray::class, 'cleared_by' => Sensitive::class, 'cleared_on' => 'date', 'answered_on' => 'date'];
    }

    public function hasAnyYes(): bool
    {
        return in_array(true, array_map(fn ($v) => (bool) $v, (array) $this->answers), true);
    }
}
