<?php

namespace App\Models;

use App\Casts\SensitiveArray;
use App\Models\Concerns\BelongsToProfile;
use App\Models\Concerns\RespectsMemberPrivacy;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Uma simulação de acordo guardada (dívida em aberto): as propostas que a pessoa digitou (nome, total, entrada, parcelas) e o saldo da dívida na
 * data, para comparar depois com uma proposta nova. O resultado NÃO é guardado: é recalculado pela mesma conta (DebtAgreementSimulator) com o
 * saldo da época, então nunca destoa do simulador.
 *
 * Herda dono e privacidade da dívida (copiados na criação e propagados em DebtService::update) e fica fechada ao familiar que só acompanha
 * (sem `VisibleToFamily`). As propostas ficam cifradas.
 */
#[Fillable(['profile_id', 'debt_id', 'member_id', 'is_private', 'name', 'balance', 'growth_percent', 'proposals', 'created_by_user_id'])]
class DebtSimulation extends Model
{
    use BelongsToProfile, HasUuids, RespectsMemberPrivacy;

    protected function casts(): array
    {
        return [
            'balance' => 'decimal:2',
            'is_private' => 'boolean',
            'proposals' => SensitiveArray::class,
        ];
    }

    public function debt(): BelongsTo
    {
        return $this->belongsTo(Debt::class);
    }
}
