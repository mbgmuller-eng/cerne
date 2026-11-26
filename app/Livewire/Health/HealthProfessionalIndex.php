<?php

namespace App\Livewire\Health;

use App\Livewire\Concerns\RequiresActiveProfile;
use App\Livewire\Concerns\RequiresPersonalHealth;
use App\Models\HealthProfessional;
use App\Models\ProfileMember;
use App\Services\HealthProfessionalService;
use App\Support\Forms\FormConsequence;
use App\Support\Health\ExamCatalog;
use App\Support\ProfileContext;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Collection;
use InvalidArgumentException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Médicos e profissionais guardados: cadastro, quem cada um atende, os fixos no topo e o histórico de cada um. Mesma visibilidade da Agenda de
 * Saúde, por pessoa atendida (consultor, nunca).
 */
#[Layout('components.layouts.app')]
class HealthProfessionalIndex extends Component
{
    use RequiresActiveProfile, RequiresPersonalHealth;

    #[Url(as: 'ficha')]
    public string $selectedId = '';

    public string $search = '';

    public bool $showForm = false;

    public ?string $editingId = null;

    public string $name = '';

    public string $specialty = '';

    public string $registry = '';

    public string $place = '';

    public string $address = '';

    public string $phone = '';

    public string $professionalNotes = '';

    public bool $favorite = false;

    /** @var list<string> */
    public array $personIds = [];

    public ?string $confirmingDeleteId = null;

    public function mount(): void
    {
        $this->redirectOrAbortWithoutProfile();
        $this->abortUnlessHealthParticipant();
    }

    public function select(string $id): void
    {
        $this->selectedId = $this->professional($id)->id;
        $this->showForm = false;
        $this->confirmingDeleteId = null;
        $this->resetErrorBag();
    }

    public function newProfessional(): void
    {
        $this->reset('editingId', 'name', 'specialty', 'registry', 'place', 'address', 'phone', 'professionalNotes', 'favorite', 'confirmingDeleteId');
        $pessoas = $this->people();
        $minha = app(ProfileContext::class)->memberId();
        $this->personIds = array_filter([(string) ($pessoas->firstWhere('id', $minha)?->id ?? $pessoas->first()?->id)]);
        $this->resetErrorBag();
        $this->showForm = true;
    }

    public function edit(string $id): void
    {
        $p = $this->professional($id);

        $this->editingId = $p->id;
        $this->name = $p->name;
        $this->specialty = (string) $p->specialty;
        $this->registry = (string) $p->registry;
        $this->place = (string) $p->place;
        $this->address = (string) $p->address;
        $this->phone = (string) $p->phone;
        $this->professionalNotes = (string) $p->notes;
        $this->favorite = $p->is_favorite;
        $this->personIds = $p->links->pluck('member_id')->all();
        $this->confirmingDeleteId = null;
        $this->resetErrorBag();
        $this->showForm = true;
    }

    public function save(HealthProfessionalService $service): void
    {
        $this->validate([
            'name' => ['required', 'string', 'max:120'],
            'specialty' => ['nullable', 'string', 'max:80'],
            'registry' => ['nullable', 'string', 'max:40'],
            'place' => ['nullable', 'string', 'max:160'],
            'address' => ['nullable', 'string', 'max:200'],
            'phone' => ['nullable', 'string', 'max:60'],
            'professionalNotes' => ['nullable', 'string', 'max:2000'],
            'personIds' => ['array', 'min:1'],
        ], ['personIds.min' => 'Marque quem esse profissional atende.'], ['name' => 'nome', 'specialty' => 'especialidade', 'registry' => 'registro', 'place' => 'clínica ou local', 'address' => 'endereço', 'phone' => 'telefone', 'professionalNotes' => 'observações', 'personIds' => 'pessoas']);

        try {
            $salvo = $service->save($this->editingId !== null ? $this->professional($this->editingId) : null, [
                'name' => $this->name, 'specialty' => $this->specialty, 'registry' => $this->registry, 'place' => $this->place,
                'address' => $this->address, 'phone' => $this->phone, 'notes' => $this->professionalNotes, 'is_favorite' => $this->favorite,
            ], array_values($this->personIds), app(ProfileContext::class)->member());
        } catch (InvalidArgumentException|AuthorizationException $e) {
            $this->addError('personIds', $e->getMessage());

            return;
        }

        // Quem desmarcou todas as pessoas que enxergava deixa de ver a ficha: não há o que selecionar.
        $this->selectedId = $salvo->id;
        $this->showForm = false;
        session()->flash('status', 'Profissional salvo.');
    }

    public function toggleFavorite(string $id, HealthProfessionalService $service): void
    {
        try {
            $service->toggleFavorite($this->professional($id));
        } catch (AuthorizationException $e) {
            $this->addError('ficha', $e->getMessage());
        }
    }

    public function remove(string $id, HealthProfessionalService $service): void
    {
        try {
            $service->remove($this->professional($id));
        } catch (AuthorizationException $e) {
            $this->addError('ficha', $e->getMessage());
            $this->confirmingDeleteId = null;

            return;
        }

        $this->selectedId = '';
        $this->confirmingDeleteId = null;
        session()->flash('status', 'Tirado da lista. Consultas e pedidos continuam, com o nome escrito.');
    }

    /** Guarda um nome que a pessoa já tinha escrito nos registros. */
    public function keepTyped(string $key, HealthProfessionalService $service): void
    {
        $sugestao = collect($service->typedNames())->firstWhere('key', $key);
        abort_if($sugestao === null, 404);

        $this->selectedId = (string) $service->resolve($sugestao['member_id'], null, $sugestao['name'], true, [], app(ProfileContext::class)->member());
        $this->showForm = false;
        session()->flash('status', 'Guardado. Complete a especialidade e o contato quando quiser.');
    }

    public function render(HealthProfessionalService $service)
    {
        $todos = $service->visible();
        $busca = ExamCatalog::normalize($this->search);
        $lista = $busca === '' ? $todos : $todos->filter(fn (HealthProfessional $p) => str_contains(ExamCatalog::normalize($p->name.' '.$p->specialty), $busca))->values();
        $escolhido = $lista->firstWhere('id', $this->selectedId) ?? $lista->first();
        $contexto = app(ProfileContext::class);

        return view('livewire.health.health-professional-index', [
            'lista' => $lista,
            'total' => $todos->count(),
            'escolhido' => $escolhido,
            'historico' => $escolhido !== null ? $service->history($escolhido) : [],
            'podeEditar' => $escolhido !== null && $escolhido->links->contains(fn ($l) => $contexto->canWriteAsCaregiver($l->member_id)),
            'sugestoes' => $service->typedNames(),
            'pessoas' => $this->people(),
            'consequencia' => $this->showForm ? FormConsequence::healthProfessional($this->editingId !== null) : [],
        ]);
    }

    /** O id vem do navegador: só vale o que quem olha enxerga. */
    private function professional(string $id): HealthProfessional
    {
        return HealthProfessional::query()->with('links')->findOrFail($id);
    }

    private function people(): Collection
    {
        return ProfileMember::query()->visibleHere()->orderBy('role')->get();
    }
}
