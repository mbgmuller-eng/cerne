<?php

namespace App\Models;

use App\Casts\Sensitive;
use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToProfile;
use App\Models\Concerns\InvalidatesDashboard;
use App\Models\Concerns\RespectsMemberPrivacy;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Um aporte (valor positivo) ou uma retirada (negativo) de um objetivo, com a data. Só existe para o objetivo SEM ligação a investimento ou consórcio:
 * o que ele tem é o saldo inicial (`goals.current_amount`) mais a soma destes lançamentos (`Goal::accumulated`).
 *
 * Herda dono e privacidade do objetivo (copiados na criação e propagados em `GoalsIndex::save`). Entra na lista de permitidos da família
 * (`VisibleToFamily`) porque o progresso que ele mostra do objetivo (que o familiar já vê) é a soma destes lançamentos: fechado, o familiar leria
 * um progresso menor que o real. A observação fica cifrada.
 */
#[Fillable(['profile_id', 'goal_id', 'member_id', 'is_private', 'amount', 'contributed_on', 'note', 'created_by_user_id'])]
class GoalContribution extends Model
{
    use Auditable, BelongsToProfile, HasUuids, InvalidatesDashboard, RespectsMemberPrivacy, \App\Models\Concerns\VisibleToFamily;

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'contributed_on' => 'date',
            'is_private' => 'boolean',
            'note' => Sensitive::class,
        ];
    }

    public function goal(): BelongsTo
    {
        return $this->belongsTo(Goal::class);
    }

    public function isWithdrawal(): bool
    {
        return bccomp((string) $this->amount, '0', 2) < 0;
    }
}
