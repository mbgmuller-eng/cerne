<?php

namespace App\Models\Scopes;

use App\Support\ProfileContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * Importação de extrato ou fatura (DocumentUpload) herda a privacidade da conta ou do cartão a que se refere. O extrato de uma conta que alguém marcou
 * como privada traz as transações dela, e a lista e a revisão de importações são do perfil: sem isto o cônjuge as abriria. Para quem opera dentro de um
 * perfil, a importação só existe se a conta e o cartão dela estiverem visíveis para ele, ou se foi ele mesmo quem a enviou.
 *
 * Importação sem conta nem cartão (apólice, relatório de outro app, extrato de consórcio) é do perfil e fica visível aos dois. Não age sem perfil ativo (o
 * processamento em fila atravessa perfis de propósito) nem para o consultor.
 */
class UploadPrivacyScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $contexto = app(ProfileContext::class);

        if (! $contexto->hasProfile() || $contexto->isConsultant()) {
            return;
        }

        $builder->where(function (Builder $query) use ($model): void {
            $query->where($model->qualifyColumn('uploaded_by_user_id'), auth()->id())
                ->orWhere(function (Builder $herdada) use ($model): void {
                    $herdada->where(fn (Builder $c) => $c->whereNull($model->qualifyColumn('bank_account_id'))->orWhereHas('bankAccount'))
                        ->where(fn (Builder $c) => $c->whereNull($model->qualifyColumn('credit_card_id'))->orWhereHas('creditCard'));
                });
        });
    }
}