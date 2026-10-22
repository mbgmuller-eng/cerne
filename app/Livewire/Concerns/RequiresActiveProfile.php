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

        // Corretor não tem "Painel da carteira" (é tela financeira) — a
        // casa dele é Seguros da carteira.
        if (auth()->user()?->isBroker()) {
            $this->redirect(route('consultant.portfolio.insurance'));

            return;
        }

        // Quem não tem perfil próprio mas acompanha alguém (o irmão que só cuida da mãe) cai na lista de pessoas, não num 404.
        $usuario = auth()->user();

        if ($usuario !== null && ($usuario->careProfiles()->exists() || $usuario->followedProfiles()->exists())) {
            $this->redirect(route('care.index'));

            return;
        }

        abort(404);
    }
}
