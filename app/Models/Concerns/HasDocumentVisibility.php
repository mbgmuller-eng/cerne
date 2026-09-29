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
