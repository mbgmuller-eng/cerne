<?php

namespace App\Livewire\Goals;

use App\Livewire\Concerns\RequiresActiveProfile;
use App\Models\Goal;
use App\Support\Money;
use Illuminate\Support\Collection;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Tela 7 — Sonhos & Objetivos, ordenados por prioridade.
 */
#[Layout('components.layouts.app')]
class GoalsIndex extends Component
{
    use RequiresActiveProfile;

    public function mount(): void
    {
        $this->redirectOrAbortWithoutProfile();
    }

    /** @return Collection<int, Goal> */
    public function getGoalsProperty(): Collection
    {
        return Goal::query()
            ->active()
            ->byPriority()
            ->with('member', 'linkedInvestment')
            ->get();
    }

    /** @return Collection<int, Goal> */
    public function getAchievedProperty(): Collection
    {
        return Goal::query()
            ->where('status', \App\Enums\GoalStatus::Achieved)
            ->with('member')
            ->get();
    }

    public function getTotalTargetProperty(): string
    {
        return Money::sum($this->goals->pluck('estimated_value'));
    }

    public function getTotalAccumulatedProperty(): string
    {
        return Money::sum($this->goals->map(fn (Goal $g) => $g->accumulated()));
    }

    /** Soma do que precisa ser guardado por mês para cumprir os prazos. */
    public function getTotalMonthlyNeededProperty(): string
    {
        return Money::sum(
            $this->goals->map(fn (Goal $g) => $g->monthlyNeeded())->filter()
        );
    }

    public function render()
    {
        return view('livewire.goals.goals-index', [
            'goals' => $this->goals,
            'achieved' => $this->achieved,
            'totalTarget' => $this->totalTarget,
            'totalAccumulated' => $this->totalAccumulated,
            'totalMonthlyNeeded' => $this->totalMonthlyNeeded,
        ]);
    }
}
