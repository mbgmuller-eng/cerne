<?php

namespace App\Models;

use App\Enums\GymLoadMode;
use App\Models\Concerns\BelongsToProfile;
use App\Models\Concerns\IsPersonalHealthData;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * UMA série de um exercício numa sessão. Uma linha por série (não o
 * resumo "3x12@14kg"): pirâmide e queda de repetições só cabem assim.
 */
#[Fillable([
    'profile_id', 'member_id', 'session_id', 'exercise_id', 'set_number', 'reps', 'duration_seconds',
    'distance_meters', 'load_value', 'load_mode', 'load_total_kg', 'equipment_id', 'completed_at',
])]
class GymSetLog extends Model
{
    use BelongsToProfile, HasUuids, IsPersonalHealthData;

    protected static function booted(): void
    {
        // O total em kg é sempre derivado do que foi digitado + o modo —
        // nunca confiado ao chamador, senão os dois podem divergir.
        static::saving(function (self $log): void {
            $log->load_total_kg = $log->load_mode?->totalKg($log->load_value);
        });
    }

    protected function casts(): array
    {
        return [
            'load_value' => 'decimal:2',
            'load_mode' => GymLoadMode::class,
            'load_total_kg' => 'decimal:2',
            'completed_at' => 'datetime',
        ];
    }

    public function session(): BelongsTo
    {
        return $this->belongsTo(GymSession::class, 'session_id');
    }

    public function exercise(): BelongsTo
    {
        return $this->belongsTo(GymExercise::class, 'exercise_id');
    }

    public function equipment(): BelongsTo
    {
        return $this->belongsTo(GymEquipment::class, 'equipment_id');
    }
}
