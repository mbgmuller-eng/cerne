<?php

namespace App\Livewire\Concerns;

use App\Support\ProfileContext;

/**
 * Toda tela que só faz sentido dentro de um perfil (fluxo de caixa,
 * investimentos, contas...) precisa disso no `mount()`.
 *
 * Consultor sem cliente aberto cai na Carteira, não numa tela vazia —
 * ele pode ter chegado aqui direto pela URL sem ter escolhido cliente
 * pela aba Clientes (mesmo raciocínio do Dashboard). Um cliente comum
 * sem perfil ativo é um caso realmente sem carteira nenhuma pra ver —
 * aí sim o 404 é a resposta certa.
 */
trait RequiresActiveProfile
{
    protected function redirectOrAbortWithoutProfile(): void
    {
        if (app(ProfileContext::class)->profile() !== null) {
            return;
        }

        if (auth()->user()?->isConsultant()) {
            $this->redirect(route('consultant.portfolio'));

            return;
        }

        abort(404);
    }
}
