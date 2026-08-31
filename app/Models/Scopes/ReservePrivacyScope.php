<?php

namespace App\Models\Scopes;

use App\Enums\Necessity;
use App\Support\ProfileContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * Privacidade da reserva de paz/oportunidade — não é um campo próprio
 * (`financial_reserves` não tem `is_private`), é DERIVADA: se o dono da
 * reserva tem qualquer gasto essencial marcado como oculto, o TOTAL da
 * reserva já entrega o que o gasto escondido devia proteger (o valor é
 * calculado a partir da média desses gastos — ver
 * InvestorProfile::peaceReserveTarget()). Por isso a reserva individual
 * fica oculta do cônjuge automaticamente, sem precisar de uma segunda
 * decisão que a pessoa teria que lembrar de tomar.
 *
 * Reserva compartilhada (member_id nulo) nunca esconde — é calculada só a
 * partir do que já é visível aos dois.
 */
class ReservePrivacyScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $context = app(ProfileContext::class);

        if ($context->isConsultant()) {
            return;
        }

        $memberId = $context->memberId();

        $builder->where(function (Builder $query) use ($model, $memberId): void {
            $query->where($model->qualifyColumn('member_id'), $memberId)
                ->orWhereNull($model->qualifyColumn('member_id'))
                ->orWhereNotExists(function ($sub) use ($model): void {
                    $sub->selectRaw('1')
                        ->from('expense_records')
                        ->whereColumn('expense_records.member_id', $model->qualifyColumn('member_id'))
                        ->where('expense_records.necessity', Necessity::Essential->value)
                        ->where('expense_records.is_private', true);
                });
        });
    }
}
