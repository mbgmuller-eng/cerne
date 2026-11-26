<?php

namespace App\Livewire\Health\Exams;

use App\Enums\HealthExamItemStatus;
use App\Livewire\Concerns\RequiresActiveProfile;
use App\Livewire\Concerns\RequiresPersonalHealth;
use App\Livewire\Health\Exams\Concerns\FindsExamPeople;
use App\Models\HealthExamReading;
use App\Models\HealthExamRequest;
use Livewire\Attributes\Layout;
use Livewire\Component;

/** Exames: os pedidos com o andamento de cada um e os laudos esperando revisão. Mesma visibilidade da Agenda de Saúde. */
#[Layout('components.layouts.app')]
class HealthExamIndex extends Component
{
    use FindsExamPeople, RequiresActiveProfile, RequiresPersonalHealth;

    public function mount(): void
    {
        $this->redirectOrAbortWithoutProfile();
        $this->abortUnlessHealthParticipant();
    }

    public function render()
    {
        $pedidos = HealthExamRequest::query()->with(['member', 'items.appointment', 'routine', 'followup', 'createdByUser'])
            ->orderByDesc('requested_on')->orderByDesc('created_at')->get()
            // Quem ainda tem o que fazer vem primeiro; os completos ficam embaixo, como histórico.
            ->sortBy(fn (HealthExamRequest $p) => $p->items->every(fn ($i) => $i->status() === HealthExamItemStatus::Result) ? 1 : 0)
            ->values();

        return view('livewire.health.exams.health-exam-index', [
            'pedidos' => $pedidos,
            'leituras' => HealthExamReading::query()->where('source_path', '!=', '')->with('member')->latest()->get(),
            'variasPessoas' => $this->people()->count() > 1,
        ]);
    }
}
