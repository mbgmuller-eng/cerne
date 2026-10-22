<?php

namespace App\Models\Concerns;

use App\Models\Scopes\DocumentVisibilityScope;
use Illuminate\Database\Eloquent\Builder;

/**
 * Camada de visibilidade da área de Documentos — ver DocumentVisibilityScope
 * pra regra completa. Usar sempre junto com BelongsToProfile (isolamento de
 * perfil continua sendo a primeira camada).
 */
trait HasDocumentVisibility
{
    public static function bootHasDocumentVisibility(): void
    {
        static::addGlobalScope(new DocumentVisibilityScope);
        \App\Support\CaregiverWriteGuard::register(static::class);

        // O que o cuidador cadastra é de todos que cuidam daquela pessoa: já nasce liberado.
        static::creating(function ($documento): void {
            if (app(\App\Support\ProfileContext::class)->isCaregiver()) {
                $documento->setAttribute('shared_with_caregivers', true);
            }
        });
    }

    /** Documento liberado aparece ao cuidador (ver DocumentVisibilityScope); o resto da área segue fechado. */
    public static function visibleToCaregiver(): bool
    {
        return true;
    }

    /**
     * Ignora o filtro de visibilidade por categoria — reservado pra código
     * que legitimamente atravessa perfis por fora de uma sessão de usuário
     * (ainda nenhum caso construído; existe pra manter o mesmo contrato dos
     * outros escopos do app).
     */
    public static function withoutDocumentVisibilityScope(): Builder
    {
        return static::query()->withoutGlobalScope(DocumentVisibilityScope::class);
    }
}
