<?php

namespace App\Livewire\Consultant;

use App\Models\EngagementPlanTemplate;
use App\Support\Engagement\EngagementPlanCriteria;
use App\Support\Engagement\EngagementPlanTemplateFormat;
use InvalidArgumentException;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Modelos de passo a passo do consultor: a metodologia dele, escrita em texto, para aplicar ao roteiro de cada cliente.
 * Só o próprio consultor os vê (ver EngagementPlanTemplate).
 */
#[Layout('components.layouts.app')]
class EngagementTemplates extends Component
{
    public bool $showForm = false;

    public ?string $editingId = null;

    public string $name = '';

    public string $body = '';

    public function mount(): void
    {
        abort_unless(auth()->user()?->isConsultant(), 403);
    }

    public function openForm(?string $id = null): void
    {
        $this->resetValidation();
        $this->editingId = $id;

        if ($id !== null) {
            $modelo = EngagementPlanTemplate::query()->findOrFail($id);
            $this->name = $modelo->name;
            $this->body = $modelo->body;
        } else {
            $this->name = '';
            $this->body = "# Primeira etapa\n- Primeiro item\n- Segundo item @accounts";
        }

        $this->showForm = true;
    }

    /** Parte do roteiro padrão do Cerne para o consultor ajustar como quiser. */
    public function copyDefault(): void
    {
        $this->openForm();
        $this->name = 'Meu roteiro';
        $this->body = EngagementPlanTemplateFormat::defaultBody();
    }

    public function save(): void
    {
        $dados = $this->validate([
            'name' => ['required', 'string', 'max:120'],
            'body' => ['required', 'string', 'max:6000'],
        ], [], ['name' => 'nome', 'body' => 'modelo']);

        try {
            EngagementPlanTemplateFormat::parse($dados['body']);
        } catch (InvalidArgumentException $e) {
            $this->addError('body', $e->getMessage());

            return;
        }

        if ($this->editingId !== null) {
            EngagementPlanTemplate::query()->findOrFail($this->editingId)->update($dados);
        } else {
            EngagementPlanTemplate::create($dados);
        }

        $this->showForm = false;
        $this->editingId = null;
    }

    public function delete(string $id): void
    {
        EngagementPlanTemplate::query()->findOrFail($id)->delete();
    }

    public function render()
    {
        return view('livewire.consultant.engagement-templates', [
            'modelos' => EngagementPlanTemplate::query()->orderBy('name')->get()->map(fn (EngagementPlanTemplate $m) => [
                'model' => $m,
                'stages' => substr_count($m->body, "\n#") + (str_starts_with(ltrim($m->body), '#') ? 1 : 0),
            ]),
            'criterios' => EngagementPlanCriteria::options(),
        ]);
    }
}
