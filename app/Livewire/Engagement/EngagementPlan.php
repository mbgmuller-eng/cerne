<?php

namespace App\Livewire\Engagement;

use App\Models\EngagementPlanItem;
use App\Models\EngagementPlanStage;
use App\Models\EngagementPlanTemplate;
use App\Models\FinancialProfile;
use App\Services\EngagementAccess;
use App\Services\EngagementPlanService;
use App\Support\Engagement\EngagementPlanCriteria;
use App\Support\Engagement\EngagementViewer;
use App\Support\ProfileContext;
use InvalidArgumentException;
use Livewire\Component;

/**
 * Passo a passo do acompanhamento: etapas com itens, o que já foi feito e o que falta.
 * O cliente acompanha; o consultor monta (etapas, itens, modelos) e marca os itens manuais.
 */
class EngagementPlan extends Component
{
    public bool $editing = false;

    // Etapa
    public bool $showStage = false;

    public ?string $stageId = null;

    public string $stageTitle = '';

    public string $stageDescription = '';

    // Item
    public bool $showItem = false;

    public ?string $itemId = null;

    public ?string $itemStageId = null;

    public string $itemTitle = '';

    public string $itemAuto = '';

    // Modelos
    public string $templateChoice = 'default';

    public bool $showSaveTemplate = false;

    public string $templateName = '';

    public ?string $templateError = null;

    private function visitante(): EngagementViewer
    {
        return app(EngagementAccess::class)->viewer() ?? abort(404);
    }

    public function mount(): void
    {
        $visitante = $this->visitante();
        $perfil = FinancialProfile::query()->find($visitante->profileId);

        // Reavalia os itens automáticos ao abrir a tela, para o que o cliente acabou de cadastrar já aparecer.
        foreach ($visitante->consultantIds as $consultorId) {
            app(EngagementPlanService::class)->syncRelation($perfil, $consultorId);
        }
    }

    public function toggleEditing(): void
    {
        abort_unless($this->visitante()->isConsultant(), 403);

        $this->editing = ! $this->editing;
    }

    // ---- etapa

    public function openStage(?string $id = null): void
    {
        abort_unless($this->visitante()->isConsultant(), 403);

        $this->resetValidation();
        $this->stageId = $id;

        if ($id !== null) {
            $etapa = EngagementPlanStage::query()->findOrFail($id);
            $this->stageTitle = $etapa->title;
            $this->stageDescription = (string) $etapa->description;
        } else {
            $this->reset('stageTitle', 'stageDescription');
        }

        $this->showStage = true;
    }

    public function saveStage(): void
    {
        $visitante = $this->visitante();

        $dados = $this->validate([
            'stageTitle' => ['required', 'string', 'max:120'],
            'stageDescription' => ['nullable', 'string', 'max:1000'],
        ], [], ['stageTitle' => 'nome da etapa', 'stageDescription' => 'descrição']);

        $etapa = $this->stageId !== null ? EngagementPlanStage::query()->findOrFail($this->stageId) : null;

        app(EngagementPlanService::class)->saveStage($visitante, $etapa, $dados['stageTitle'], $dados['stageDescription'] ?: null);

        $this->showStage = false;
    }

    public function deleteStage(string $id): void
    {
        $visitante = $this->visitante();

        app(EngagementPlanService::class)->deleteStage($visitante, EngagementPlanStage::query()->findOrFail($id));
    }

    public function moveStage(string $id, int $direcao): void
    {
        $visitante = $this->visitante();

        app(EngagementPlanService::class)->moveStage($visitante, EngagementPlanStage::query()->findOrFail($id), $direcao);
    }

    // ---- item

    public function openItem(string $etapaId, ?string $itemId = null): void
    {
        abort_unless($this->visitante()->isConsultant(), 403);

        $this->resetValidation();
        $this->itemStageId = $etapaId;
        $this->itemId = $itemId;

        if ($itemId !== null) {
            $item = EngagementPlanItem::query()->findOrFail($itemId);
            $this->itemTitle = $item->title;
            $this->itemAuto = (string) $item->auto_check;
        } else {
            $this->reset('itemTitle', 'itemAuto');
        }

        $this->showItem = true;
    }

    public function saveItem(): void
    {
        $visitante = $this->visitante();

        $dados = $this->validate([
            'itemTitle' => ['required', 'string', 'max:160'],
            'itemAuto' => ['nullable', 'string', 'in:'.implode(',', array_keys(EngagementPlanCriteria::options()))],
        ], [], ['itemTitle' => 'texto do item', 'itemAuto' => 'critério automático']);

        $item = $this->itemId !== null ? EngagementPlanItem::query()->findOrFail($this->itemId) : null;

        app(EngagementPlanService::class)->saveItem($visitante, $item, $this->itemStageId, $dados['itemTitle'], $dados['itemAuto'] ?: null);

        $this->showItem = false;
    }

    public function deleteItem(string $id): void
    {
        $visitante = $this->visitante();

        app(EngagementPlanService::class)->deleteItem($visitante, EngagementPlanItem::query()->findOrFail($id));
    }

    public function setDone(string $id, bool $feito): void
    {
        $visitante = $this->visitante();

        app(EngagementPlanService::class)->setManualDone($visitante, EngagementPlanItem::query()->findOrFail($id), $feito);
    }

    // ---- modelos

    /** Acrescenta ao roteiro as etapas do modelo escolhido (o padrão do Cerne ou um dos modelos do consultor). */
    public function applyTemplate(): void
    {
        $visitante = $this->visitante();
        abort_unless($visitante->isConsultant(), 403);

        $corpo = $this->templateChoice === 'default'
            ? \App\Support\Engagement\EngagementPlanTemplateFormat::defaultBody()
            : EngagementPlanTemplate::query()->findOrFail($this->templateChoice)->body;

        try {
            app(EngagementPlanService::class)->applyBody($visitante, $corpo);
            $this->templateError = null;
        } catch (InvalidArgumentException $e) {
            $this->templateError = $e->getMessage();
        }
    }

    public function openSaveTemplate(): void
    {
        abort_unless($this->visitante()->isConsultant(), 403);

        $this->resetValidation();
        $this->templateName = '';
        $this->showSaveTemplate = true;
    }

    public function saveTemplate(): void
    {
        $visitante = $this->visitante();

        $dados = $this->validate(['templateName' => ['required', 'string', 'max:120']], [], ['templateName' => 'nome do modelo']);

        app(EngagementPlanService::class)->saveAsTemplate($visitante, $dados['templateName']);

        $this->showSaveTemplate = false;
    }

    public function render()
    {
        $visitante = $this->visitante();

        $etapas = EngagementPlanStage::query()->with('items')->orderBy('position')->orderBy('created_at')->get();
        $itens = $etapas->flatMap->items;

        return view('livewire.engagement.engagement-plan', [
            'visitante' => $visitante,
            'etapas' => $etapas,
            'totalItens' => $itens->count(),
            'itensFeitos' => $itens->whereNotNull('done_at')->count(),
            'etapasFeitas' => $etapas->filter(fn (EngagementPlanStage $e) => $e->isDone())->count(),
            'criterios' => EngagementPlanCriteria::options(),
            'modelos' => $visitante->isConsultant() ? EngagementPlanTemplate::query()->orderBy('name')->get() : collect(),
            'nomeDoPerfil' => app(ProfileContext::class)->profile()?->profile_name,
        ]);
    }
}
