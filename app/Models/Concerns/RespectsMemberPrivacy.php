<?php

namespace App\Models\Concerns;

use App\Models\Scopes\MemberPrivacyScope;
use App\Support\ProfileContext;
use Illuminate\Database\Eloquent\Builder;

/**
 * Segunda camada de isolamento, aplicada SOBRE o BelongsToProfile.
 *
 * O tenancy responde "de qual casal é este dado?". Esta trait responde
 * "dentro do casal, quem pode ver este lançamento?", conforme o campo do
 * próprio registro:
 *
 *   - registro da família (member_id nulo): visível aos dois, sempre;
 *   - registro marcado como privado (`is_private`): só quem é o dono dele
 *     (e o consultor). Conta e cartão já tinham seu próprio campo pra isso
 *     ANTES desta trait existir — `visible_to_partner` (ver
 *     HasSharingFlags) — então usam ele em vez de `is_private`, pra não
 *     ter dois campos de privacidade fazendo a mesma pergunta;
 *   - contas e cartões conjuntos (is_joint) escapam da restrição.
 *
 * Não há mais exceção pro dono do perfil — a regra vale igual pros dois do
 * casal (ver MemberPrivacyScope).
 */
trait RespectsMemberPrivacy
{
    public static function bootRespectsMemberPrivacy(): void
    {
        static::addGlobalScope(new MemberPrivacyScope);
    }

    /** O model tem a flag de conta/cartão conjunto? */
    public static function hasJointFlag(): bool
    {
        return in_array('is_joint', (new static)->getFillable(), true);
    }

    /** O model já tem seu próprio campo de privacidade (ver HasSharingFlags)? */
    public static function hasVisibleToPartnerFlag(): bool
    {
        return in_array('visible_to_partner', (new static)->getFillable(), true);
    }

    /**
     * Ignora a privacidade do casal deliberadamente. Reservado para o que
     * legitimamente precisa da visão completa — relatórios do consultor,
     * jobs de fechamento, a checagem de "existe algo oculto?" — e nunca
     * para telas acessíveis diretamente ao membro secundário.
     */
    public static function withoutPrivacyScope(): Builder
    {
        return static::query()->withoutGlobalScope(MemberPrivacyScope::class);
    }

    /** O membro logado pode EDITAR este registro? */
    public function isEditableByCurrentMember(): bool
    {
        $context = app(ProfileContext::class);

        if ($context->isConsultant()) {
            return true;
        }

        // Registro próprio ou da família: sempre editável pelo membro.
        return $this->member_id === null || $this->member_id === $context->memberId();
    }
}
