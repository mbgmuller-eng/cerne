<?php

namespace App\Livewire\Activities;

use App\Livewire\Activities\Concerns\ActivityScreen;
use App\Services\Activities\ReadinessService;
use App\Support\Activities\ReadinessQuestions;
use App\Support\Forms\FormConsequence;
use DomainException;
use Livewire\Attributes\Layout;
use Livewire\Component;

/** As perguntas de prontidão antes de um plano de atividades ou de começar a correr. Só a própria pessoa responde e vê. */
#[Layout('components.layouts.app')]
class ActivityReadinessPage extends Component
{
    use ActivityScreen;

    /** @var array<string, string> chave da pergunta => 'yes' ou 'no' */
    public array $answers = [];

    public ?string $clearance = null;

    public string $clearedBy = '';

    public string $clearedOn = '';

    public function mount(ReadinessService $readiness): void
    {
        $this->guardActivityScreen();
        $r = $readiness->record();

        // Respostas vencidas não vêm preenchidas: a pessoa responde de novo, sem só confirmar o que marcou antes.
        if ($r !== null && $readiness->status() !== ReadinessService::EXPIRED) {
            $this->answers = array_map(fn ($v) => $v ? 'yes' : 'no', (array) $r->answers);
            $this->clearance = $r->clearance;
            $this->clearedBy = (string) $r->cleared_by;
            $this->clearedOn = (string) $r->cleared_on?->toDateString();
        }
    }

    public function save(ReadinessService $readiness): void
    {
        $this->validate(
            ['clearedBy' => ['nullable', 'string', 'max:160'], 'clearedOn' => ['nullable', 'date', 'before_or_equal:today']],
            ['clearedOn.before_or_equal' => 'A data da liberação não pode ser no futuro.'],
            ['clearedBy' => 'quem liberou', 'clearedOn' => 'data da liberação'],
        );

        try {
            $readiness->save($this->answers, $this->clearance, $this->clearedBy, $this->clearedOn ?: null);
        } catch (DomainException $e) {
            $this->addError('form', $e->getMessage());

            return;
        }

        session()->flash('status', 'Respostas guardadas.');
    }

    public function render(ReadinessService $readiness)
    {
        $algumSim = in_array('yes', $this->answers, true);
        $completo = count(array_intersect_key($this->answers, ReadinessQuestions::ALL)) === count(ReadinessQuestions::ALL);

        return view('livewire.activities.activity-readiness', [
            'perguntas' => ReadinessQuestions::ALL,
            'algumSim' => $algumSim,
            'situacao' => $readiness->status(),
            'registro' => $readiness->record(),
            'consequencia' => $completo ? FormConsequence::readiness($algumSim, $this->clearance) : [],
        ]);
    }
}
