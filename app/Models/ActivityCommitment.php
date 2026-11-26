<?php

namespace App\Models;

use App\Casts\Sensitive;
use App\Enums\ActivityType;
use App\Models\Concerns\BelongsToProfile;
use App\Models\Concerns\IsPersonalHealthData;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;

/** O que a pessoa já faz: um compromisso fixo (tem dia da semana) ou uma prática sem dia certo (tem vezes por semana). */
#[Fillable(['profile_id', 'member_id', 'activity', 'custom_name', 'weekday', 'times_per_week', 'minutes', 'effort', 'is_active'])]
class ActivityCommitment extends Model
{
    use BelongsToProfile, HasUuids, IsPersonalHealthData;

    protected function casts(): array
    {
        return [
            'activity' => ActivityType::class, 'custom_name' => Sensitive::class, 'weekday' => 'integer', 'times_per_week' => 'integer',
            'minutes' => 'integer', 'effort' => 'integer', 'is_active' => 'boolean',
        ];
    }

    public function isFixed(): bool
    {
        return $this->weekday !== null;
    }

    public function name(): string
    {
        return $this->activity === ActivityType::Other && $this->custom_name ? $this->custom_name : $this->activity->label();
    }
}
