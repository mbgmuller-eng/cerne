<?php

namespace App\Livewire\Health\Exams;

use App\Livewire\Concerns\RequiresActiveProfile;
use App\Livewire\Concerns\RequiresPersonalHealth;
use App\Livewire\Health\Concerns\PicksHealthProfessional;
use App\Livewire\Health\Exams\Concerns\FindsExamPeople;
use App\Models\HealthFollowup;
use App\Models\HealthProfessional;
use App\Models\HealthRoutine;
use App\Services\HealthExamService;
use App\Services\HealthProfessionalService;
use App\Support\Forms\FormConsequence;
use App\Support\Health\ExamCatalog;
use Carbon\CarbonImmutable;
use InvalidArgumentException;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Montar o pedido: escolher o tipo, marcar na lista o que o médico pediu e escrever o detalhe de cada exame. Nada vem marcado. Cada exame fica na
 * própria área, e ao salvar nasce um pedido por área, com o que é comum (quem pediu, data, check-up e acompanhamento) preenchido uma vez só.
 */
#[Layout('components.layouts.app')]
class HealthExamBuilder extends Component
{
    use FindsExamPeople, PicksHealthProfessional, RequiresActiveProfile, RequiresPersonalHealth;

    public string $memberId = '';

    /** A aba da lista. Não é a área do pedido: a área é de cada exame. */
    public string $category = 'blood';

    public string $search = '';

    /** @var list<array{name: string, detail: string, category: string}> */
    public array $selected = [];

    /** @var array<string, string> área => nome do pedido */
    public array $titles = [];

    /** @var array<string, string> área => observações */
    public array $notes = [];

    public string $requestedOn = '';

    public string $requestedBy = '';

    public string $routineId = '';

    public string $followupId = '';

    public function mount(): void
    {
        $this->redirectOrAbortWithoutProfile();
        $this->abortUnlessHealthParticipant();

        $this->memberId = $this->defaultPersonId();
        $this->requestedOn = CarbonImmutable::today()->toDateString();

        // Veio de um check-up, de um acompanhamento ou da ficha de um profissional: a pessoa é a dele.
        $rotina = ($id = request()->query('rotina')) && is_string($id) ? HealthRoutine::query()->find($id) : null;
        $acompanhamento = ($id = request()->query('acompanhamento')) && is_string($id) ? HealthFollowup::query()->find($id) : null;

        if ($rotina !== null || $acompanhamento !== null) {
            $this->memberId = $rotina?->member_id ?? $acompanhamento->member_id;
            $this->routineId = (string) $rotina?->id;
            $this->followupId = $acompanhamento !== null && $acompanhamento->member_id === $this->memberId ? $acompanhamento->id : '';
        }

        $this->openWithProfessional();
    }

    private function openWithProfessional(): void
    {
        $id = request()->query('profissional');
        $profissional = is_string($id) && $id !== '' ? app(HealthProfessionalService::class)->visible()->firstWhere('id', $id) : null;

        if ($profissional === null) {
            return;
        }

        if (! $profissional->links->contains('member_id', $this->memberId)) {
            $this->memberId = $profissional->links->first()->member_id;
        }

        $this->professionalId = $profissional->id;
        $this->requestedBy = $profissional->name;
    }

    /** Rotina, acompanhamento, profissional escolhido e lista anterior são de uma pessoa: trocar a pessoa solta as escolhas. */
    public function updatedMemberId(): void
    {
        $this->routineId = '';
        $this->followupId = '';
        $this->professionalId = '';
    }

    public function chooseCategory(string $categoria): void
    {
        if (! isset(ExamCatalog::CATEGORIES[$categoria])) {
            return;
        }

        $this->category = $categoria;
        $this->search = '';
    }

    public function toggle(string $nome): void
    {
        $posicao = $this->positionOf($nome);

        if ($posicao !== null) {
            array_splice($this->selected, $posicao, 1);

            return;
        }

        // A área é a do catálogo; para o que a pessoa digitou antes, a área em que ela o pôs; senão, a da aba aberta.
        $area = ExamCatalog::categoryOf($nome)
            ?? app(HealthExamService::class)->customAreas($this->personOrFail($this->memberId)->id)[ExamCatalog::normalize($nome)]
            ?? $this->category;

        $this->add($nome, $area);
    }

    public function addTyped(): void
    {
        $nome = trim($this->search);
        $this->add($nome, ExamCatalog::categoryOf($nome) ?? $this->category);
        $this->search = '';
    }

    public function remove(int $posicao): void
    {
        array_splice($this->selected, $posicao, 1);
    }

    /** A pessoa pode mudar a área de um exame marcado. */
    public function updatedSelected(): void
    {
        foreach ($this->selected as $i => $exame) {
            if (! isset(ExamCatalog::CATEGORIES[$exame['category'] ?? ''])) {
                $this->selected[$i]['category'] = 'other';
            }

            $this->titles[$this->selected[$i]['category']] ??= ExamCatalog::CATEGORIES[$this->selected[$i]['category']][1];
        }
    }

    public function repeatPrevious(HealthExamService $service): void
    {
        $anterior = $service->previousOrder($this->personOrFail($this->memberId)->id, $this->category);

        foreach ($anterior?->items ?? [] as $item) {
            $this->add($item->name, ExamCatalog::categoryOf($item->name) ?? $this->category, (string) $item->detail);
        }
    }

    public function save(HealthExamService $service): void
    {
        $this->validate([
            'memberId' => ['required', 'string'],
            'requestedOn' => ['required', 'date', 'before_or_equal:today', 'after:2000-01-01'],
            'requestedBy' => ['nullable', 'string', 'max:120'],
            'selected' => ['array', 'min:1', 'max:'.HealthExamService::MAX_ITEMS],
            'selected.*.name' => ['required', 'string', 'max:160'],
            'selected.*.detail' => ['nullable', 'string', 'max:300'],
            'titles.*' => ['nullable', 'string', 'max:120'],
            'notes.*' => ['nullable', 'string', 'max:2000'],
        ], ['selected.min' => 'Marque pelo menos um exame.'], ['requestedOn' => 'data do pedido', 'requestedBy' => 'quem pediu', 'selected' => 'exames', 'titles.*' => 'nome do pedido', 'notes.*' => 'observações']);

        $areas = [];

        foreach ($this->usedAreas() as $area) {
            $titulo = trim($this->titles[$area] ?? ExamCatalog::CATEGORIES[$area][1]);

            if ($titulo === '') {
                $this->addError('titles.'.$area, 'Dê um nome ao pedido de '.ExamCatalog::CATEGORIES[$area][0].'.');

                return;
            }

            $areas[$area] = [
                'title' => $titulo,
                'notes' => $this->notes[$area] ?? null,
                'items' => array_values(array_filter($this->selected, fn (array $s) => $s['category'] === $area)),
            ];
        }

        $membro = $this->personOrFail($this->memberId);
        $rotina = $this->routineId !== '' ? HealthRoutine::query()->where('member_id', $membro->id)->findOrFail($this->routineId) : null;
        $acompanhamento = $this->followupId !== '' ? HealthFollowup::query()->where('member_id', $membro->id)->findOrFail($this->followupId) : null;

        try {
            $pedidos = $service->createByArea($membro, [
                'requested_on' => $this->requestedOn, 'requested_by' => $this->requestedBy,
                'professional_id' => $this->resolveProfessional($membro->id, $this->requestedBy),
            ], $areas, $rotina, $acompanhamento, $this->author());
        } catch (InvalidArgumentException $e) {
            $this->addError('selected', $e->getMessage());

            return;
        }

        if ($pedidos->count() === 1) {
            session()->flash('status', 'Pedido guardado. Agora marque as datas.');
            $this->redirect(route('health.exams.show', $pedidos->first()->id), navigate: true);

            return;
        }

        session()->flash('status', $pedidos->count().' pedidos guardados, um para cada área. Abra cada um para marcar as datas.');
        $this->redirect(route('health.exams.index'), navigate: true);
    }

    public function render(HealthExamService $service)
    {
        $pessoas = $this->people();
        $membroId = (string) ($pessoas->firstWhere('id', $this->memberId)?->id ?? '');
        $opcoes = ExamCatalog::search($this->category, $this->search);
        $busca = ExamCatalog::normalize($this->search);
        $minhas = $membroId !== '' ? $service->customAreas($membroId) : [];

        // Os que a própria pessoa já digitou aparecem na área em que ela os pôs, e na busca.
        foreach ($membroId !== '' ? $service->customNames($membroId) : [] as $nome) {
            $area = $minhas[ExamCatalog::normalize($nome)] ?? 'other';

            if ($busca !== '' ? str_contains(ExamCatalog::normalize($nome), $busca) : $area === $this->category) {
                $opcoes[] = ['name' => $nome, 'hint' => 'Você já usou este', 'category' => $area];
            }
        }

        $porArea = [];

        foreach ($this->usedAreas() as $area) {
            $porArea[$area] = array_filter($this->selected, fn (array $s) => $s['category'] === $area);
        }

        return view('livewire.health.exams.health-exam-builder', [
            'pessoas' => $pessoas,
            'categorias' => ExamCatalog::CATEGORIES,
            'opcoes' => $opcoes,
            'marcados' => array_map(fn (array $s) => ExamCatalog::normalize($s['name']), $this->selected),
            'porArea' => $porArea,
            'podeAdicionar' => $busca !== '' && ! collect($opcoes)->contains(fn (array $o) => ExamCatalog::normalize($o['name']) === $busca),
            'anterior' => $membroId !== '' ? $service->previousOrder($membroId, $this->category) : null,
            'rotinas' => $membroId !== '' ? HealthRoutine::query()->where('member_id', $membroId)->get() : collect(),
            'acompanhamentos' => $membroId !== '' ? HealthFollowup::query()->where('member_id', $membroId)->orderByDesc('started_on')->get() : collect(),
            'campoProfissional' => $this->professionalField($membroId, $this->requestedBy),
            'consequencia' => FormConsequence::examOrder(collect($porArea)->mapWithKeys(fn (array $itens, string $area) => [ExamCatalog::CATEGORIES[$area][0] => count($itens)])->all()),
        ]);
    }

    protected function professionalPersonId(): string
    {
        return $this->memberId;
    }

    protected function applyProfessional(HealthProfessional $profissional): void
    {
        $this->requestedBy = $profissional->name;
    }

    /** @return list<string> as áreas com exame marcado, na ordem das abas */
    private function usedAreas(): array
    {
        return array_values(array_intersect(array_keys(ExamCatalog::CATEGORIES), array_column($this->selected, 'category')));
    }

    private function add(string $nome, string $area, string $detalhe = ''): void
    {
        if ($nome !== '' && $this->positionOf($nome) === null && count($this->selected) < HealthExamService::MAX_ITEMS) {
            $this->selected[] = ['name' => mb_substr($nome, 0, 160), 'detail' => $detalhe, 'category' => $area];
            $this->titles[$area] ??= ExamCatalog::CATEGORIES[$area][1];
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
