<?php

namespace App\Livewire\Health\Exams;

use App\Livewire\Concerns\RequiresActiveProfile;
use App\Livewire\Concerns\RequiresPersonalHealth;
use App\Livewire\Health\Exams\Concerns\FindsExamPeople;
use App\Models\HealthFollowup;
use App\Models\HealthRoutine;
use App\Services\HealthExamService;
use App\Support\Forms\FormConsequence;
use App\Support\Health\ExamCatalog;
use Carbon\CarbonImmutable;
use InvalidArgumentException;
use Livewire\Attributes\Layout;
use Livewire\Component;

/** Montar o pedido: escolher o tipo, marcar na lista o que o médico pediu e escrever o detalhe de cada exame. Nada vem marcado. */
#[Layout('components.layouts.app')]
class HealthExamBuilder extends Component
{
    use FindsExamPeople, RequiresActiveProfile, RequiresPersonalHealth;

    public string $memberId = '';

    public string $category = 'blood';

    public string $search = '';

    /** @var list<array{name: string, detail: string}> */
    public array $selected = [];

    public string $title = '';

    public bool $titleEdited = false;

    public string $requestedOn = '';

    public string $requestedBy = '';

    public string $notes = '';

    public string $routineId = '';

    public string $followupId = '';

    public function mount(): void
    {
        $this->redirectOrAbortWithoutProfile();
        $this->abortUnlessHealthParticipant();

        $this->memberId = $this->defaultPersonId();
        $this->requestedOn = CarbonImmutable::today()->toDateString();
        $this->title = ExamCatalog::CATEGORIES[$this->category][1];

        // Veio de um check-up ou de um acompanhamento: a pessoa é a dele.
        $rotina = ($id = request()->query('rotina')) && is_string($id) ? HealthRoutine::query()->find($id) : null;
        $acompanhamento = ($id = request()->query('acompanhamento')) && is_string($id) ? HealthFollowup::query()->find($id) : null;

        if ($rotina !== null || $acompanhamento !== null) {
            $this->memberId = $rotina?->member_id ?? $acompanhamento->member_id;
            $this->routineId = (string) $rotina?->id;
            $this->followupId = $acompanhamento !== null && $acompanhamento->member_id === $this->memberId ? $acompanhamento->id : '';
        }
    }

    /** Rotina, acompanhamento e lista anterior são de uma pessoa: trocar a pessoa solta as escolhas. */
    public function updatedMemberId(): void
    {
        $this->routineId = '';
        $this->followupId = '';
    }

    public function updatedTitle(): void
    {
        $this->titleEdited = true;
    }

    public function chooseCategory(string $categoria): void
    {
        if (! isset(ExamCatalog::CATEGORIES[$categoria])) {
            return;
        }

        $this->category = $categoria;
        $this->search = '';

        // O nome sugerido acompanha o tipo só enquanto a pessoa não escreveu o dela nem marcou exame.
        if (! $this->titleEdited && $this->selected === []) {
            $this->title = ExamCatalog::CATEGORIES[$categoria][1];
        }
    }

    public function toggle(string $nome): void
    {
        $posicao = $this->positionOf($nome);

        if ($posicao !== null) {
            array_splice($this->selected, $posicao, 1);
        } else {
            $this->add($nome);
        }
    }

    public function addTyped(): void
    {
        $this->add(trim($this->search));
        $this->search = '';
    }

    public function remove(int $posicao): void
    {
        array_splice($this->selected, $posicao, 1);
    }

    public function repeatPrevious(HealthExamService $service): void
    {
        $anterior = $service->previousOrder($this->personOrFail($this->memberId)->id, $this->category);

        foreach ($anterior?->items ?? [] as $item) {
            $this->add($item->name, (string) $item->detail);
        }
    }

    public function save(HealthExamService $service): void
    {
        $this->validate([
            'memberId' => ['required', 'string'],
            'title' => ['required', 'string', 'max:120'],
            'requestedOn' => ['required', 'date', 'before_or_equal:today', 'after:2000-01-01'],
            'requestedBy' => ['nullable', 'string', 'max:120'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'selected' => ['array', 'min:1', 'max:'.HealthExamService::MAX_ITEMS],
            'selected.*.name' => ['required', 'string', 'max:160'],
            'selected.*.detail' => ['nullable', 'string', 'max:300'],
        ], ['selected.min' => 'Marque pelo menos um exame.'], ['title' => 'nome do pedido', 'requestedOn' => 'data do pedido', 'requestedBy' => 'quem pediu', 'notes' => 'observações', 'selected' => 'exames']);

        $membro = $this->personOrFail($this->memberId);
        $rotina = $this->routineId !== '' ? HealthRoutine::query()->where('member_id', $membro->id)->findOrFail($this->routineId) : null;
        $acompanhamento = $this->followupId !== '' ? HealthFollowup::query()->where('member_id', $membro->id)->findOrFail($this->followupId) : null;

        try {
            $pedido = $service->createOrder($membro, [
                'title' => $this->title, 'category' => $this->category, 'requested_on' => $this->requestedOn,
                'requested_by' => $this->requestedBy, 'notes' => $this->notes, 'items' => $this->selected,
            ], $rotina, $acompanhamento, $this->author());
        } catch (InvalidArgumentException $e) {
            $this->addError('selected', $e->getMessage());

            return;
        }

        session()->flash('status', 'Pedido guardado. Agora marque as datas.');
        $this->redirect(route('health.exams.show', $pedido->id), navigate: true);
    }

    public function render(HealthExamService $service)
    {
        $pessoas = $this->people();
        $membroId = (string) ($pessoas->firstWhere('id', $this->memberId)?->id ?? '');
        $opcoes = ExamCatalog::search($this->category, $this->search);
        $busca = ExamCatalog::normalize($this->search);
        $meus = $membroId !== '' ? $service->customNames($membroId) : [];

        // Os que a própria pessoa já digitou: aparecem em "Outros" e na busca.
        if ($busca !== '' || $this->category === 'other') {
            foreach ($meus as $nome) {
                if ($busca === '' || str_contains(ExamCatalog::normalize($nome), $busca)) {
                    $opcoes[] = ['name' => $nome, 'hint' => 'Você já usou este', 'category' => 'other'];
                }
            }
        }

        return view('livewire.health.exams.health-exam-builder', [
            'pessoas' => $pessoas,
            'categorias' => ExamCatalog::CATEGORIES,
            'opcoes' => $opcoes,
            'marcados' => array_map(fn (array $s) => ExamCatalog::normalize($s['name']), $this->selected),
            'podeAdicionar' => $busca !== '' && ! collect($opcoes)->contains(fn (array $o) => ExamCatalog::normalize($o['name']) === $busca),
            'anterior' => $membroId !== '' ? $service->previousOrder($membroId, $this->category) : null,
            'rotinas' => $membroId !== '' ? HealthRoutine::query()->where('member_id', $membroId)->get() : collect(),
            'acompanhamentos' => $membroId !== '' ? HealthFollowup::query()->where('member_id', $membroId)->orderByDesc('started_on')->get() : collect(),
            'consequencia' => FormConsequence::examOrder(count($this->selected)),
        ]);
    }

    private function add(string $nome, string $detalhe = ''): void
    {
        if ($nome !== '' && $this->positionOf($nome) === null && count($this->selected) < HealthExamService::MAX_ITEMS) {
            $this->selected[] = ['name' => mb_substr($nome, 0, 160), 'detail' => $detalhe];
        }
    }

    private function positionOf(string $nome): ?int
    {
        foreach ($this->selected as $i => $s) {
            if (ExamCatalog::normalize($s['name']) === ExamCatalog::normalize($nome)) {
                return $i;
            }
        }

        return null;
    }
}
