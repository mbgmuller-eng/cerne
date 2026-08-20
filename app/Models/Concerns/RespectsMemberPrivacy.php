<?php

namespace App\Models\Concerns;

use App\Models\Scopes\MemberPrivacyScope;
use App\Support\ProfileContext;
use Illuminate\Database\Eloquent\Builder;

/**
 * Segunda camada de isolamento, aplicada SOBRE o BelongsToProfile.
 *
 * O tenancy responde "de qual casal é este dado?". Esta trait responde
 * "dentro do casal, quem pode ver?", conforme profile_access_settings
 * (seção 3 da especificação):
 *
 *   - dono e consultor enxergam tudo, sempre;
 *   - membro secundário com o domínio em `own_only` só vê os próprios
 *     registros e os da família (member_id nulo);
 *   - contas e cartões conjuntos (is_joint) escapam da restrição.
 *
 * O model declara qual coluna de visibilidade o governa:
 *
 *     protected static string $privacyDomain = 'expense_visibility';
 */
trait RespectsMemberPrivacy
{
    public static function bootRespectsMemberPrivacy(): void
    {
        static::addGlobalScope(new MemberPrivacyScope);
    }

    /** Coluna de profile_access_settings que governa este model. */
    public static function privacyDomain(): string
    {
        return static::$privacyDomain;
    }

    /** O model tem a flag de conta/cartão conjunto? */
    public static function hasJointFlag(): bool
    {
        return in_array('is_joint', (new static)->getFillable(), true);
    }

    /**
     * Ignora a privacidade do casal deliberadamente. Reservado para o que
     * legitimamente precisa da visão completa — consolidado do dono,
     * relatórios do consultor, jobs de fechamento — e nunca para telas
     * acessíveis ao membro secundário.
     */
    public static function withoutPrivacyScope(): Builder
    {
        return static::query()->withoutGlobalScope(MemberPrivacyScope::class);
    }

    /** O membro logado pode EDITAR este registro? */
    public function isEditableByCurrentMember(): bool
    {
        $context = app(ProfileContext::class);

        if (! $context->isRestrictedMember()) {
            return true;
        }

        // Registro próprio ou da família: sempre editável pelo membro.
        if ($this->member_id === null || $this->member_id === $context->memberId()) {
            return true;
        }

        return (bool) $context->profile()?->settings()->can_edit_partner_records;
    }
}
