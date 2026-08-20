<?php

namespace App\Models\Concerns;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * As três flags de compartilhamento de contas e cartões.
 *
 * As regras se encadeiam, e a ordem importa:
 *
 *   is_joint = true              -> força visible_to_partner e
 *                                   included_in_consolidated em true
 *   visible_to_partner = false   -> força included_in_consolidated em false
 *
 * A segunda é a que a especificação enuncia ("incluída no consolidado
 * exige visível para o cônjuge"): um valor que o cônjuge não pode ver não
 * pode ser somado num total que ele enxerga, senão o patrimônio consolidado
 * denuncia por subtração o que a privacidade escondeu.
 */
trait HasSharingFlags
{
    public static function bootHasSharingFlags(): void
    {
        $normalize = function (Model $model): void {
            // Atributo ausente na criação ainda não recebeu o default do
            // banco e leria como null. Tratar null como "não compartilha"
            // desligaria do consolidado toda conta criada sem informar as
            // flags — que é o caso comum.
            $joint = $model->getAttribute('is_joint') ?? false;
            $visible = $model->getAttribute('visible_to_partner') ?? true;
            $consolidated = $model->getAttribute('included_in_consolidated') ?? true;

            if ($joint) {
                // Conta do casal: pertence aos dois por natureza.
                $model->setAttribute('is_joint', true);
                $model->setAttribute('visible_to_partner', true);
                $model->setAttribute('included_in_consolidated', true);

                return;
            }

            $model->setAttribute('is_joint', false);
            $model->setAttribute('visible_to_partner', $visible);

            // O que o cônjuge não pode ver não pode ser somado num total
            // que ele enxerga: o consolidado denunciaria por subtração o
            // que a privacidade escondeu.
            $model->setAttribute('included_in_consolidated', $visible ? $consolidated : false);
        };

        static::creating($normalize);
        static::updating($normalize);
    }

    /** Contas/cartões que entram no patrimônio consolidado. */
    public function scopeConsolidated(Builder $query): Builder
    {
        return $query->where('included_in_consolidated', true);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /** As flags podem ser editadas, ou estão travadas por serem conjuntas? */
    public function sharingFlagsAreLocked(): bool
    {
        return (bool) $this->is_joint;
    }
}
