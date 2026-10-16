<?php

namespace App\Livewire\Engagement;

use App\Models\FinancialProfile;
use App\Services\EngagementAccess;
use App\Services\EngagementPlanReport;
use App\Support\Engagement\EngagementViewer;
use App\Support\ProfileContext;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Acompanhamento: a área de trabalho do consultor financeiro com o cliente (reuniões, tarefas, demandas e notas).
 *
 * Só existe para quem tem vínculo ativo com um consultor financeiro: o cliente sem consultor não tem a área, e o corretor
 * não entra. O consultor chega aqui já dentro do perfil de um cliente (ver ProfileSwitchController).
 */
#[Layout('components.layouts.app')]
class EngagementHome extends Component
{
    public function mount(): void
    {
        abort_if($this->visitante() === null, 404);
    }

    private function visitante(): ?EngagementViewer
    {
        return app(EngagementAccess::class)->viewer();
    }

    /** O plano em PDF. Só o consultor baixa: é ele quem decide o que sai da relação de consultoria. */
    public function baixarPlano()
    {
        $visitante = $this->visitante();

        abort_unless($visitante?->isConsultant(), 403);

        $perfil = FinancialProfile::query()->findOrFail($visitante->profileId);
        $relatorio = app(EngagementPlanReport::class);

        return response()->streamDownload(
            fn () => print ($relatorio->pdf($visitante->profileId, $visitante->consultantId())),
            $relatorio->fileName($perfil->profile_name),
        );
    }

    public function render()
    {
        $visitante = $this->visitante();
        abort_if($visitante === null, 404);

        $perfil = app(ProfileContext::class)->profile();

        return view('livewire.engagement.engagement-home', [
            'ehConsultor' => $visitante->isConsultant(),
            'nomeDoPerfil' => $perfil->profile_name,
            'consultores' => $visitante->isClient() ? app(EngagementAccess::class)->activeConsultants($perfil) : collect(),
        ]);
    }
}
