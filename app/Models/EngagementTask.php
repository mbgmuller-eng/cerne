<?php

namespace App\Models;

use App\Enums\EngagementTaskOwner;
use App\Enums\EngagementTaskStatus;
use App\Models\Concerns\BelongsToProfile;
use App\Models\Scopes\EngagementScope;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Tarefa do acompanhamento, com dono (cliente ou consultor), prazo e situação.
 *
 * Visível só dentro da relação de consultoria (EngagementScope), além do isolamento por perfil.
 */
#[Fillable(['profile_id', 'consultant_id', 'meeting_id', 'title', 'description', 'owner', 'assignee_member_id', 'due_date', 'status', 'completed_at', 'created_by_user_id'])]
class EngagementTask extends Model
{
    use BelongsToProfile, HasUuids;

    /** O nome de quem é responsável aparece em toda lista de tarefas: carregar junto evita a consulta por linha (e o lazy loading barrado fora de produção). */
    protected $with = ['assignee'];

    protected static function booted(): void
    {
        static::addGlobalScope(new EngagementScope);
    }

    protected function casts(): array
    {
        return [
            'due_date' => 'date',
            'completed_at' => 'datetime',
            'owner' => EngagementTaskOwner::class,
            'status' => EngagementTaskStatus::class,
        ];
    }

    /**
     * A pessoa do casal a quem a tarefa foi dada (só quando o dono é o cliente). Sem os escopos de perfil de propósito: o consultor lê tarefas de vários
     * clientes de uma vez (agenda, semana) e o escopo esconderia o nome; quem vê a tarefa já vê quem é o responsável.
     */
    public function assignee(): BelongsTo
    {
        return $this->belongsTo(ProfileMember::class, 'assignee_member_id')->withoutGlobalScopes();
    }

    /** "Ana" quando a tarefa é de uma pessoa do casal; senão "Cliente" ou "Consultor". */
    public function responsibleLabel(): string
    {
        return $this->assignee?->name ?? $this->owner->label();
    }

    public function consultant(): BelongsTo
    {
        return $this->belongsTo(User::class, 'consultant_id');
    }

    public function meeting(): BelongsTo
    {
        return $this->belongsTo(EngagementMeeting::class, 'meeting_id');
    }

    public function isOpen(): bool
    {
        return $this->status !== EngagementTaskStatus::Done;
    }

    public function isOverdue(): bool
    {
        return $this->isOpen() && $this->due_date !== null && $this->due_date->lt(today());
    }
}
