<?php

namespace App\Livewire;

use App\Services\DashboardService;
use App\Support\ProfileContext;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Tela 2 — Visão Geral.
 *
 * Todos os números vêm do DashboardService, que agrega em SQL e guarda em
 * cache por perfil e por quem pergunta.
 */
#[Layout('components.layouts.app')]
class Dashboard extends Component
{
    public function render(DashboardService $dashboard)
    {
        $context = app(ProfileContext::class);
        $profile = $context->profile();

        if ($profile === null) {
            return view('livewire.dashboard-empty', [
                'isConsultant' => auth()->user()?->isConsultant() ?? false,
            ]);
        }

        return view('livewire.dashboard', [
            'profile' => $profile,
            'member' => $context->member(),
            'dados' => $dashboard->overview(),
        ]);
    }
}
