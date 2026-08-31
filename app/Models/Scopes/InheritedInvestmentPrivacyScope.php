<?php

namespace App\Models\Scopes;

use App\Support\ProfileContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * Transação e rentabilidade herdam a privacidade do investimento — ninguém
 * vai querer marcar movimentação por movimentação como oculta, então a
 * decisão é uma só, no ativo (`investment_records.is_private`).
 *
 * Diferente de CreditCardInvoice/FixedBillPayment (que não têm escopo
 * nenhum, confiando que a tela sempre chega pela conta já filtrada): aqui
 * a tela (InvestmentsIndex::getPerformanceProperty()/getTransactionsProperty())
 * consulta a tabela diretamente, então o escopo precisa aplicar o filtro
 * ele mesmo, via join com o investimento pai.
 *
 * Rentabilidade da carteira inteira (investment_id nulo, ver
 * InvestmentPerformance::isPortfolioWide()) não tem de quem herdar — fica
 * visível por padrão, mesmo raciocínio de "sem configuração, transparente".
 */
class InheritedInvestmentPrivacyScope implements Scope
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
                ->orWhereNull($model->qualifyColumn('investment_id'))
                ->orWhereHas('investment', fn (Builder $q) => $q->where('is_private', false));
        });
    }
}
