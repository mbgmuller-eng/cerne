<?php

namespace App\Models;

use App\Enums\EngagementMeetingFormat;
use App\Enums\EngagementMeetingStatus;
use App\Models\Concerns\BelongsToProfile;
use App\Models\Scopes\EngagementScope;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Reunião entre o consultor e o cliente: horário, pauta, o que levar e, depois de realizada, a ata (summary).
 *
 * Visível só dentro da relação de consultoria (EngagementScope), além do isolamento por perfil.
 */
#[Fillable(['profile_id', 'consultant_id', 'series_id', 'scheduled_at', 'duration_minutes', 'format', 'location', 'agenda', 'prep_notes', 'status', 'summary', 'created_by_user_id'])]
class EngagementMeeting extends Model
{
    use BelongsToProfile, HasUuids;

    protected static function booted(): void
    {
        static::addGlobalScope(new EngagementScope);
    }

    protected function casts(): array
    {
        return [
            'scheduled_at' => 'datetime',
            'duration_minutes' => 'integer',
            'format' => EngagementMeetingFormat::class,
            'status' => EngagementMeetingStatus::class,
        ];
    }

    public function consultant(): BelongsTo
    {
        return $this->belongsTo(User::class, 'consultant_id');
    }

    public function tasks(): HasMany
    {
        return $this->hasMany(EngagementTask::class, 'meeting_id');
    }

    /** Itens da pauta, um por linha. */
    public function agendaItems(): array
    {
        return array_values(array_filter(array_map('trim', preg_split('/\R/u', (string) $this->agenda) ?: [])));
    }

    public function endsAt(): \Carbon\CarbonInterface
    {
        return $this->scheduled_at->copy()->addMinutes($this->duration_minutes);
    }
}
