<?php

namespace App\Livewire\Admin;

use App\Enums\GymMeasureType;
use App\Enums\GymMuscleGroup;
use App\Models\GymExerciseCatalog;
use App\Models\GymExerciseSuggestion;
use App\Services\GymCatalogImageService;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\WithFileUploads;

/**
 * Catálogo compartilhado de exercícios da Academia: o admin cria, edita,
 * desativa e exclui entradas, e sobe as fotos (dois quadros: início e fim do
 * movimento). Também revisa a fila ANÔNIMA de sugestões (exercício que
 * alguém digitou no próprio treino e que o catálogo não tem) — a fila mostra
 * só nome, grupo, tipo e "pedido N vezes", nunca quem pediu (treino é dado de
 * Saúde; ver GymExerciseSuggestion).
 *
 * Mexer aqui nunca altera o exercício pessoal de ninguém: quem escolheu um
 * item do catálogo ganhou uma CÓPIA da foto (GymPlanService::copyCatalogImage).
 */
#[Layout('components.layouts.app')]
class AdminExercises extends Component
{
    use WithFileUploads;

    public string $busca = '';

    public bool $somenteSemFoto = false;

    public bool $showForm = false;

    public ?string $editandoId = null;

    /** Sugestão em aprovação: ao salvar, ela sai da fila. */
    public ?string $sugestaoId = null;

    public string $formName = '';

    public string $formGroup = 'chest';

    public string $formType = 'load_reps';

    public string $formEquipment = '';

    public string $formNotes = '';

    public bool $formActive = true;

    public $foto1 = null;

    public $foto2 = null;

    public bool $removerFoto1 = false;

    public bool $removerFoto2 = false;

    public function mount(): void
    {
        abort_unless(auth()->user()?->isPlatformAdmin(), 403);
    }

    public function novo(): void
    {
        $this->resetForm();
        $this->showForm = true;
    }

    public function editar(string $id): void
    {
        $exercicio = $this->catalogo()->findOrFail($id);

        $this->resetForm();
        $this->editandoId = $exercicio->id;
        $this->formName = $exercicio->name;
        $this->formGroup = $exercicio->muscle_group->value;
        $this->formType = $exercicio->measure_type->value;
        $this->formEquipment = (string) $exercicio->equipment_hint;
        $this->formNotes = (string) $exercicio->notes;
        $this->formActive = $exercicio->is_active;
        $this->showForm = true;
    }

    /** Abre o formulário já preenchido com o que o usuário pediu; falta só escolher equipamento e subir as fotos. */
    public function aprovarSugestao(string $id): void
    {
        $sugestao = GymExerciseSuggestion::query()->pending()->findOrFail($id);

        $this->resetForm();
        $this->sugestaoId = $sugestao->id;
        $this->formName = $sugestao->name;
        $this->formGroup = $sugestao->muscle_group->value;
        $this->formType = $sugestao->measure_type->value;
        $this->showForm = true;
    }

    public function dispensarSugestao(string $id): void
    {
        $sugestao = GymExerciseSuggestion::query()->pending()->findOrFail($id);
        $sugestao->dismiss();

        session()->flash('status', "\"{$sugestao->name}\" saiu da fila e não volta a ser sugerido.");
    }

    public function fecharForm(): void
    {
        $this->showForm = false;
        $this->resetForm();
    }

    public function salvar(GymCatalogImageService $imagens): void
    {
        $limite = (int) config('cerne.gym_images.max_kb');

        $dados = $this->validate([
            'formName' => ['required', 'string', 'max:120'],
            'formGroup' => ['required', Rule::in(GymMuscleGroup::values())],
            'formType' => ['required', Rule::in(GymMeasureType::values())],
            'formEquipment' => ['nullable', 'string', 'max:120'],
            'formNotes' => ['nullable', 'string', 'max:2000'],
            'formActive' => ['boolean'],
            'foto1' => ['nullable', 'image', "max:{$limite}"],
            'foto2' => ['nullable', 'image', "max:{$limite}"],
        ], attributes: [
            'formName' => 'nome', 'formGroup' => 'grupo muscular', 'formType' => 'tipo de medida',
            'formEquipment' => 'equipamento', 'formNotes' => 'observações', 'foto1' => 'quadro 1', 'foto2' => 'quadro 2',
        ]);

        if ($this->nomeEmUso($dados['formName'], $this->editandoId)) {
            $this->addError('formName', 'Já existe um exercício com esse nome no catálogo.');

            return;
        }

        $campos = [
            'name' => trim($dados['formName']),
            'muscle_group' => $dados['formGroup'],
            'measure_type' => $dados['formType'],
            'equipment_hint' => $this->vazioParaNulo($dados['formEquipment']),
            'notes' => $this->vazioParaNulo($dados['formNotes']),
            'is_active' => $dados['formActive'],
        ];

        if ($this->editandoId !== null) {
            $exercicio = $this->catalogo()->findOrFail($this->editandoId);
            $exercicio->update($campos);
        } else {
            $exercicio = GymExerciseCatalog::create($campos + ['profile_id' => null]);
        }

        // Remoções antes dos envios: tirar o quadro 1 sobe o 2, e um envio novo
        // no mesmo salvamento entra por cima do que sobrou.
        if ($this->removerFoto2) {
            $imagens->remove($exercicio, 2);
        }
        if ($this->removerFoto1) {
            $imagens->remove($exercicio, 1);
        }
        $imagens->store($exercicio, $this->foto1, $this->foto2);

        if ($this->sugestaoId !== null) {
            GymExerciseSuggestion::query()->find($this->sugestaoId)?->markApproved();
        }

        $nome = $exercicio->name;
        $this->fecharForm();
        session()->flash('status', "\"{$nome}\" salvo no catálogo.");
    }

    public function alternarAtivo(string $id): void
    {
        $exercicio = $this->catalogo()->findOrFail($id);
        $exercicio->update(['is_active' => ! $exercicio->is_active]);
    }

    public function excluir(string $id, GymCatalogImageService $imagens): void
    {
        $exercicio = $this->catalogo()->findOrFail($id);
        $nome = $exercicio->name;

        $imagens->purge($exercicio);
        $exercicio->delete();

        session()->flash('status', "\"{$nome}\" saiu do catálogo. Os treinos que já usam esse exercício não mudam.");
    }

    public function getEditandoProperty(): ?GymExerciseCatalog
    {
        return $this->editandoId !== null ? $this->catalogo()->find($this->editandoId) : null;
    }

    /** @return Collection<int, GymExerciseSuggestion> */
    public function getSugestoesProperty(): Collection
    {
        return GymExerciseSuggestion::query()->pending()->orderByDesc('times_suggested')->orderBy('name')->get();
    }

    public function render()
    {
        $todos = $this->catalogo()->orderBy('name')->get();
        $procurado = GymExerciseCatalog::normalizeName($this->busca);

        $filtrados = $todos
            ->when($procurado !== '', fn (Collection $c) => $c->filter(
                fn (GymExerciseCatalog $e): bool => str_contains(GymExerciseCatalog::normalizeName($e->name), $procurado),
            ))
            ->when($this->somenteSemFoto, fn (Collection $c) => $c->filter(fn (GymExerciseCatalog $e): bool => $e->image_path === null));

        $grupos = collect(GymMuscleGroup::cases())
            ->map(fn (GymMuscleGroup $grupo): array => [
                'grupo' => $grupo,
                'exercicios' => $filtrados->filter(fn (GymExerciseCatalog $e): bool => $e->muscle_group === $grupo)->values(),
            ])
            ->filter(fn (array $linha): bool => $linha['exercicios']->isNotEmpty())
            ->values();

        return view('livewire.admin.admin-exercises', [
            'grupos' => $grupos,
            'total' => $todos->count(),
            'semFoto' => $todos->filter(fn (GymExerciseCatalog $e): bool => $e->image_path === null)->count(),
        ]);
    }

    private function catalogo()
    {
        return GymExerciseCatalog::withoutTaxonomyScope()->shared();
    }

    private function nomeEmUso(string $nome, ?string $exceto): bool
    {
        $procurado = GymExerciseCatalog::normalizeName($nome);

        return $this->catalogo()
            ->when($exceto !== null, fn ($q) => $q->where('id', '!=', $exceto))
            ->pluck('name')
            ->contains(fn (string $existente): bool => GymExerciseCatalog::normalizeName($existente) === $procurado);
    }

    private function vazioParaNulo(?string $valor): ?string
    {
        $valor = trim((string) $valor);

        return $valor === '' ? null : $valor;
    }

    private function resetForm(): void
    {
        $this->reset('editandoId', 'sugestaoId', 'formName', 'formEquipment', 'formNotes', 'foto1', 'foto2', 'removerFoto1', 'removerFoto2');
        $this->formGroup = 'chest';
        $this->formType = 'load_reps';
        $this->formActive = true;
        $this->resetErrorBag();
    }
}
