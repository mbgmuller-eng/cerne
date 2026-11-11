<?php

namespace App\Livewire\Hooks;

use App\Support\ProfileContext;
use Livewire\ComponentHook;

/**
 * Cada componente Livewire nasce preso ao perfil que estava ativo quando a tela abriu (o id vai no memo assinado do snapshot, que o navegador não
 * consegue alterar). O perfil ativo mora na SESSÃO, e a sessão é uma só para todas as abas: o consultor que abre o cliente A numa aba e troca para o
 * cliente B em outra, voltando a salvar na primeira, gravaria no cliente B. Agora essa requisição é recusada e a tela pede para recarregar.
 */
class BindToActiveProfile extends ComponentHook
{
    public function dehydrate($context): void
    {
        $context->addMemo('cerne_profile', app(ProfileContext::class)->profileId());
    }

    public function hydrate($memo): void
    {
        $preso = $memo['cerne_profile'] ?? null;

        if ($preso !== null && $preso !== app(ProfileContext::class)->profileId()) {
            abort(409, 'O perfil aberto mudou em outra aba. Recarregue a página para continuar.');
        }
    }
}
