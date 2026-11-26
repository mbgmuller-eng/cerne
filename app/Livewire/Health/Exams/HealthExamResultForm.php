<?php

namespace App\Livewire\Health\Exams;

use App\Enums\ProcessingStatus;
use App\Livewire\Concerns\RequiresActiveProfile;
use App\Livewire\Concerns\RequiresPersonalHealth;
use App\Livewire\Health\Exams\Concerns\FindsExamPeople;
use App\Models\HealthExamReading;
use App\Models\HealthExamReport;
use App\Models\HealthExamRequest;
use App\Services\HealthExamReadingService;
use App\Services\HealthExamResultService;
use App\Support\Forms\FormConsequence;
use App\Support\Health\ExamCatalog;
use InvalidArgumentException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * A revisão de um resultado: o que a leitura do PDF trouxe (para conferir) ou o que a pessoa digita à mão. Nada é gravado como resultado antes
 * de salvar aqui.
 */
#[Layout('components.layouts.app')]
class HealthExamResultForm extends Component
{
    use FindsExamPeople, \App\Livewire\Health\Concerns\PicksHealthProfessional, RequiresActiveProfile, RequiresPersonalHealth;

    #[Locked]
    public ?string $readingId = null;

    #[Locked]
    public ?string $pedidoId = null;

    /** O formulário já recebeu o que a leitura trouxe (ou o ponto de partida da digitação): o que a pessoa corrige não é sobrescrito. */
    #[Locked]
    public bool $loaded = false;

    public string $memberId = '';

    public string $kind = HealthExamReport::KIND_VALUES;

    public string $performedOn = '';

    public string $lab = '';

    public string $doctor = '';

    public string $examName = '';

    public string $conclusion = '';

    public string $highlight = '';

    public string $readingNotes = '';

    /** @var list<array{name: string, value: string, unit: string, reference: string, doubt: string, on: bool}> */
    public array $values = [];

    /** @var list<string> */
    public array $itemIds = [];

    public function mount(?string $leitura = null): void
    {
        $this->redirectOrAbortWithoutProfile();
        $this->abortUnlessHealthParticipant();
        // Quem assina um laudo (um radiologista, por exemplo) raramente é o médico da pessoa: só guarda se ela marcar.
        $this->resetProfessional(null, false);

        if ($leitura !== null) {
            $registro = HealthExamReading::query()->findOrFail($leitura);
            $this->readingId = $registro->id;
            $this->pedidoId = $registro->request_id !== null ? HealthExamRequest::query()->whereKey($registro->request_id)->value('id') : null;
            $this->memberId = $registro->member_id;

            return;
        }

        $pedido = ($id = request()->query('pedido')) && is_string($id) ? HealthExamRequest::query()->findOrFail($id) : null;
        $this->pedidoId = $pedido?->id;
        $this->memberId = $pedido?->member_id ?? $this->defaultPersonId();
        abort_unless($this->canWriteFor($this->memberId), 403);

        $this->startBlank($pedido);
    }

    public function addRow(): void
    {
        if (count($this->values) < HealthExamResultService::MAX_VALUES) {
            $this->values[] = $this->blankRow();
        }
    }

    public function removeRow(int $posicao): void
    {
        array_splice($this->values, $posicao, 1);
    }

    public function save(HealthExamResultService $resultados, HealthExamReadingService $leitor): void
    {
        $this->validate([
            'kind' => ['required', 'in:values,report'],
            'performedOn' => ['required', 'date', 'before_or_equal:today', 'after:1900-01-01'],
            'lab' => ['nullable', 'string', 'max:160'],
            'doctor' => ['nullable', 'string', 'max:160'],
            'examName' => ['nullable', 'string', 'max:160', 'required_if:kind,report'],
            'conclusion' => ['nullable', 'string', 'max:4000', 'required_if:kind,report'],
            'highlight' => ['nullable', 'string', 'max:300'],
            'values' => ['array', 'max:'.HealthExamResultService::MAX_VALUES],
            'values.*.name' => ['nullable', 'string', 'max:160'],
            'values.*.value' => ['nullable', 'string', 'max:120'],
            'values.*.unit' => ['nullable', 'string', 'max:60'],
            'values.*.reference' => ['nullable', 'string', 'max:300'],
            'itemIds' => ['array'],
        ], [
            'examName.required_if' => 'Informe o nome do exame.',
            'conclusion.required_if' => 'Copie a conclusão do laudo.',
        ], ['performedOn' => 'data do exame', 'lab' => 'local', 'doctor' => 'médico', 'examName' => 'exame', 'conclusion' => 'conclusão', 'highlight' => 'medida em destaque']);

        $dados = [
            'kind' => $this->kind, 'performed_on' => $this->performedOn, 'lab' => $this->lab, 'doctor' => $this->doctor,
            'exam_name' => $this->examName, 'conclusion' => $this->conclusion, 'highlight' => $this->highlight,
            'values' => array_values(array_filter($this->values, fn (array $v) => (bool) ($v['on'] ?? true))),
        ];
        $itens = array_values($this->itemIds);

        try {
            if ($this->readingId !== null) {
                $leitura = HealthExamReading::query()->findOrFail($this->readingId);
                $dados['professional_id'] = $this->resolveProfessional($leitura->member_id, $this->doctor);
                $leitor->confirm($leitura, $dados, $itens, $this->author());
            } else {
                $pedido = $this->pedidoId !== null ? HealthExamRequest::query()->findOrFail($this->pedidoId) : null;
                $membro = $this->personOrFail($pedido?->member_id ?? $this->memberId);
                $dados['professional_id'] = $this->resolveProfessional($membro->id, $this->doctor);
                $resultados->save($membro, $pedido, $dados, $itens, $this->author());
            }
        } catch (InvalidArgumentException $e) {
            $this->addError('form', $e->getMessage());

            return;
        }

        session()->flash('status', 'Resultado salvo.');
        $this->redirect($this->pedidoId !== null ? route('health.exams.show', $this->pedidoId) : route('health.exams.results'), navigate: true);
    }

    public function discard(HealthExamReadingService $leitor): void
    {
        $leitor->discard(HealthExamReading::query()->findOrFail($this->readingId));

        session()->flash('status', 'Laudo descartado. O arquivo foi apagado.');
        $this->redirect($this->pedidoId !== null ? route('health.exams.show', $this->pedidoId) : route('health.exams.index'), navigate: true);
    }

    public function render(HealthExamReadingService $leitor)
    {
        $leitura = $this->readingId !== null ? HealthExamReading::query()->findOrFail($this->readingId) : null;
        $pedido = $this->pedidoId !== null ? HealthExamRequest::query()->with(['items'])->find($this->pedidoId) : null;

        if ($leitura !== null && ! $this->loaded && $leitura->isOpen()) {
            if ($leitura->status === ProcessingStatus::Completed && is_array($leitura->summary)) {
                $this->loadFrom($leitura->summary, $leitor, $pedido);
            } elseif ($leitura->status === ProcessingStatus::Failed) {
                // Não deu para ler: a pessoa digita, e o PDF vai junto ao salvar.
                $this->startBlank($pedido);
            }
        }

        $marcados = count(array_filter($this->values, fn (array $v) => (bool) $v['on'] && trim((string) $v['name']) !== '' && trim((string) $v['value']) !== ''));

        return view('livewire.health.exams.health-exam-result-form', [
            'campoProfissional' => $this->professionalField($this->memberId, $this->doctor),
            'leitura' => $leitura,
            'pedido' => $pedido,
            'esperando' => $leitura !== null && $leitura->isOpen() && in_array($leitura->status, [ProcessingStatus::Pending, ProcessingStatus::Processing], true),
            'encerrada' => $leitura !== null && ! $leitura->isOpen(),
            'podeEscrever' => $this->canWriteFor($this->memberId),
            'pessoas' => $pedido === null && $leitura === null ? $this->people() : collect(),
            'nomeDaPessoa' => $this->people()->firstWhere('id', $this->memberId)?->name,
            'itensAbertos' => $pedido?->items->whereNull('report_id')->values() ?? collect(),
            'consequencia' => FormConsequence::examResult($this->kind, $marcados, count($this->itemIds), $leitura !== null, $pedido !== null),
        ]);
    }

    protected function professionalPersonId(): string
    {
        return $this->memberId;
    }

    protected function applyProfessional(\App\Models\HealthProfessional $profissional): void
    {
        $this->doctor = $profissional->name;
    }

    /** @param  array<string, mixed>  $resumo */
    private function loadFrom(array $resumo, HealthExamReadingService $leitor, ?HealthExamRequest $pedido): void
    {
        $this->kind = $resumo['kind'];
        $this->performedOn = $resumo['performed_on'];
        $this->lab = $resumo['lab'];
        $this->doctor = $resumo['doctor'];
        $this->examName = $resumo['exam_name'];
        $this->conclusion = $resumo['conclusion'];
        $this->highlight = $resumo['highlight'];
        $this->readingNotes = $resumo['notes'];
        $this->values = array_map(fn (array $v) => $v + ['on' => true], $resumo['values']);
        $this->itemIds = $leitor->matchItems($pedido, $resumo);
        $this->loaded = true;
    }

    /** Ponto de partida da digitação: uma linha para cada resultado que costuma vir nos exames pedidos, sem valor nenhum preenchido. */
    private function startBlank(?HealthExamRequest $pedido): void
    {
        $linhas = [];

        foreach ($pedido?->items()->whereNull('report_id')->get() ?? [] as $item) {
            $exame = ExamCatalog::examFor($item->name);

            foreach (['Hemoglobina', 'Glicemia de jejum', 'Colesterol total', 'HDL', 'LDL', 'Triglicerídeos'] as $marcador) {
                if ($exame !== null && ExamCatalog::markerFor($marcador)['exam'] === $exame) {
                    $linhas[$marcador] = $this->blankRow($marcador);
                }
            }

            if ($exame !== null && ($direto = ExamCatalog::markerFor($exame)) !== null && $direto['exam'] === $exame && ! isset($linhas[$direto['name']])) {
                $linhas[$direto['name']] = $this->blankRow($direto['name']);
            }
        }

        $this->values = $linhas !== [] ? array_values($linhas) : [$this->blankRow()];
        $this->loaded = true;
    }

    /** @return array{name: string, value: string, unit: string, reference: string, doubt: string, on: bool} */
    private function blankRow(string $nome = ''): array
    {
        return ['name' => $nome, 'value' => '', 'unit' => '', 'reference' => '', 'doubt' => '', 'on' => true];
    }
}
