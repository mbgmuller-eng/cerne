<?php

namespace App\Livewire\Health\Exams;

use App\Enums\HealthExamItemStatus;
use App\Livewire\Concerns\RequiresActiveProfile;
use App\Livewire\Concerns\RequiresPersonalHealth;
use App\Livewire\Health\Exams\Concerns\FindsExamPeople;
use App\Models\HealthExamItem;
use App\Models\HealthExamReading;
use App\Models\HealthExamReport;
use App\Models\HealthExamRequest;
use App\Services\HealthExamResultService;
use App\Services\HealthExamService;
use App\Support\Forms\FormConsequence;
use App\Support\Health\ExamCatalog;
use InvalidArgumentException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Component;

/** Um pedido: os exames e a situação de cada um, a marcação das datas e os resultados que já chegaram. */
#[Layout('components.layouts.app')]
class HealthExamShow extends Component
{
    use FindsExamPeople, \App\Livewire\Health\Concerns\PicksHealthProfessional, RequiresActiveProfile, RequiresPersonalHealth;

    /** A prévia de "Separar por área" está aberta. */
    public bool $splitting = false;

    #[Locked]
    public string $pedidoId = '';

    /** @var list<string> */
    public array $selected = [];

    public bool $scheduling = false;

    public string $date = '';

    public string $time = '';

    public string $place = '';

    /** '' | add | edit */
    public string $panel = '';

    public string $newName = '';

    public string $newDetail = '';

    public string $title = '';

    public string $requestedBy = '';

    public string $notes = '';

    public bool $confirmingDelete = false;

    public function mount(string $pedido): void
    {
        $this->redirectOrAbortWithoutProfile();
        $this->abortUnlessHealthParticipant();

        $this->pedidoId = HealthExamRequest::query()->findOrFail($pedido)->id;
    }

    public function open(string $panel): void
    {
        $this->resetErrorBag();
        $this->panel = $this->panel === $panel ? '' : $panel;

        if ($this->panel === 'add') {
            $this->reset('newName', 'newDetail');
        }

        if ($this->panel === 'edit') {
            $pedido = $this->order();
            $this->title = $pedido->title;
            $this->requestedBy = (string) $pedido->requested_by;
            $this->notes = (string) $pedido->notes;
            $this->resetProfessional($pedido->professional_id, (string) $pedido->requested_by === '');
        }
    }

    public function split(HealthExamService $service): void
    {
        try {
            $pedidos = $service->splitByArea($this->order());
        } catch (InvalidArgumentException $e) {
            $this->addError('selected', $e->getMessage());
            $this->splitting = false;

            return;
        }

        session()->flash('status', 'Separado em '.$pedidos->count().' pedidos: '.$pedidos->map->title->implode(', ').'.');
        $this->redirect(route('health.exams.index'), navigate: true);
    }

    protected function professionalPersonId(): string
    {
        return $this->order()->member_id;
    }

    protected function applyProfessional(\App\Models\HealthProfessional $profissional): void
    {
        $this->requestedBy = $profissional->name;
    }

    public function startScheduling(): void
    {
        $this->resetErrorBag();

        if ($this->selected === []) {
            $this->addError('selected', 'Marque na lista os exames que serão feitos no mesmo dia.');

            return;
        }

        $this->scheduling = true;
    }

    public function schedule(HealthExamService $service): void
    {
        $this->validate([
            'date' => ['required', 'date'],
            'time' => ['required', 'date_format:H:i'],
            'place' => ['nullable', 'string', 'max:160'],
        ], attributes: ['date' => 'data', 'time' => 'hora', 'place' => 'local']);

        try {
            $service->schedule($this->order(), array_values($this->selected), "{$this->date} {$this->time}", $this->place !== '' ? $this->place : null, $this->author());
        } catch (InvalidArgumentException $e) {
            $this->addError('date', $e->getMessage());

            return;
        }

        $this->reset('selected', 'scheduling', 'date', 'time', 'place');
        session()->flash('status', 'Marcado na Agenda de Saúde.');
    }

    public function addItem(HealthExamService $service): void
    {
        $this->validate(
            ['newName' => ['required', 'string', 'max:160'], 'newDetail' => ['nullable', 'string', 'max:300']],
            attributes: ['newName' => 'exame', 'newDetail' => 'detalhe do pedido'],
        );

        try {
            $service->addItem($this->order(), $this->newName, $this->newDetail, $this->author());
        } catch (InvalidArgumentException $e) {
            $this->addError('newName', $e->getMessage());

            return;
        }

        $this->reset('newName', 'newDetail');
        $this->panel = '';
    }

    public function removeItem(string $itemId, HealthExamService $service): void
    {
        try {
            $service->removeItem($this->item($itemId));
        } catch (InvalidArgumentException $e) {
            $this->addError('selected', $e->getMessage());

            return;
        }

        $this->selected = array_values(array_diff($this->selected, [$itemId]));
    }

    public function saveEdit(HealthExamService $service): void
    {
        $this->validate([
            'title' => ['required', 'string', 'max:120'],
            'requestedBy' => ['nullable', 'string', 'max:120'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ], attributes: ['title' => 'nome do pedido', 'requestedBy' => 'quem pediu', 'notes' => 'observações']);

        $pedido = $this->order();
        $service->updateOrder($pedido, [
            'title' => $this->title, 'requested_by' => $this->requestedBy, 'notes' => $this->notes,
            'professional_id' => $this->resolveProfessional($pedido->member_id, $this->requestedBy),
        ]);
        $this->panel = '';
    }

    public function deleteReport(string $reportId, HealthExamResultService $service): void
    {
        $service->delete(HealthExamReport::query()->where('request_id', $this->pedidoId)->findOrFail($reportId));
    }

    public function delete(HealthExamService $service): void
    {
        $service->deleteOrder($this->order());

        session()->flash('status', 'Pedido excluído. Os resultados guardados e os compromissos continuam.');
        $this->redirect(route('health.exams.index'), navigate: true);
    }

    public function render()
    {
        $pedido = $this->order()->load(['member', 'routine', 'followup', 'createdByUser', 'items.appointment', 'reports.values', 'reports.document']);
        $escolhidos = $pedido->items->whereIn('id', $this->selected)->filter(fn (HealthExamItem $i) => $i->status() === HealthExamItemStatus::Pending);

        return view('livewire.health.exams.health-exam-show', [
            'separacao' => app(HealthExamService::class)->splitPreview($pedido),
            'areas' => ExamCatalog::CATEGORIES,
            'campoProfissional' => $this->panel === 'edit' ? $this->professionalField($pedido->member_id, $this->requestedBy) : null,
            'pedido' => $pedido,
            'leituras' => HealthExamReading::query()->where('request_id', $pedido->id)->where('source_path', '!=', '')->with('member')->latest()->get(),
            'podeEscrever' => $this->canWriteFor($pedido->member_id),
            'sugestoes' => $this->panel === 'add' ? array_column(ExamCatalog::search($pedido->category ?? 'blood'), 'name') : [],
            'consequencia' => $this->scheduling ? FormConsequence::examSchedule($escolhidos->pluck('name')->all()) : [],
        ]);
    }

    private function order(): HealthExamRequest
    {
        return HealthExamRequest::query()->findOrFail($this->pedidoId);
    }

    /** Sempre dentro do pedido aberto: o id vem do navegador. */
    private function item(string $id): HealthExamItem
    {
        return HealthExamItem::query()->where('request_id', $this->pedidoId)->findOrFail($id);
    }
}
