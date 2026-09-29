<?php

namespace App\Models\Scopes;

use App\Enums\DocumentCategory;
use App\Support\ProfileContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * Dono e cônjuge sempre veem todo documento do perfil (é o ponto da área —
 * BelongsToProfile já isola por perfil, esta camada só entra em ação pro
 * consultor/corretor). Cada categoria decide sozinha se aparece pra eles:
 *
 * - `insurance_policy` NÃO tem flag própria — herda a visibilidade da
 *   apólice vinculada (`whereHas('insurancePolicy')` reaproveita o próprio
 *   escopo da InsurancePolicy: BelongsToProfile + RespectsBrokerVisibility
 *   + RespectsMemberPrivacy). Documento sem apólice vinculada (ex.: apólice
 *   apagada) para de aparecer pro profissional — mais seguro por padrão.
 * - `other` é a ÚNICA com flag manual (`visible_to_professional`).
 * - Toda outra categoria (CNH, passaporte, certificado, exame de saúde) é
 *   dado pessoal/de saúde — travada, sem exceção nenhuma, mesma regra dura
 *   de CoupleHealthScope: consultor/corretor NUNCA veem, ponto final.
 */
class DocumentVisibilityScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $context = app(ProfileContext::class);

        if (! $context->isConsultant()) {
            return;
        }

        $builder->where(function (Builder $query) use ($model): void {
            $query->where(function (Builder $q): void {
                $q->where('category', DocumentCategory::InsurancePolicy->value)
                    ->whereHas('insurancePolicy');
            })->orWhere(function (Builder $q) use ($model): void {
                $q->where('category', DocumentCategory::Other->value)
                    ->where($model->qualifyColumn('visible_to_professional'), true);
            });
        });
    }
}
