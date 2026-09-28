<?php

namespace App\Livewire\Concerns;

use App\Support\ProfileContext;

/**
 * Tela de Saúde pessoal (treino): só o dono abre. Consultor e corretor
 * levam 403 mesmo com o perfil do cliente aberto — o escopo já devolveria
 * zero linhas (PersonalHealthScope), mas uma tela vazia sugeriria que o
 * cliente "não tem treino", em vez de dizer que ali não é lugar deles.
 *
 * Usar DEPOIS de redirectOrAbortWithoutProfile(): sem perfil ativo quem
 * decide é ela (redireciona o profissional pra carteira, 404 pro resto).
 */
trait RequiresPersonalHealth
{
    protected function abortUnlessPersonalHealthOwner(): void
    {
        $context = app(ProfileContext::class);

        if ($context->profile() === null) {
            return;
        }

        abort_if($context->isConsultant() || $context->memberId() === null, 403);
    }
}
