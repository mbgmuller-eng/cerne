<?php

namespace App\Livewire\Health;

use App\Enums\HealthCareCategory;
use App\Enums\HealthCareIntervalUnit;
use App\Livewire\Concerns\RequiresActiveProfile;
use App\Livewire\Concerns\RequiresPersonalHealth;
use App\Models\HealthCareItem;
use App\Models\ProfileMember;
use App\Services\HealthCareItemService;
use App\Support\ProfileContext;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Cuidados e itens de saúde com troca periódica (aparelho auditivo, palmilha, óculos,
 * próteses, meias de compressão...): frequência, última vez, próxima vez e lembrete.
 * Mesma visibilidade da Ficha de Saúde (CoupleHealthScope): o casal vê, o consultor
 * não vê nada.
 */
#[Layout('components.layouts.app')]
class HealthCareItemIndex extends Component
{
    use RequiresActiveProfile, RequiresPersonalHealth;

    public bool $showForm = false;
    public ?string $editingId = null;
    public ?string $memberId = null;
    public string $category = 'hearing_aid';
    public string $name = '';
    public string $deviceName = '';
    public string $intervalValue = '';
    public string $intervalUnit = 'day';
    public string $lastDoneOn = '';
    public string $notes = '';

    public function mount(): void
    {
        $this->redirectOrAbortWithoutProfile();
        $this->abortUnlessPersonalHealthOwner();
    }

    public function newItem(): void
    {
        $this->resetForm();
        $this->memberId = $this->membros()->first()?->id;
        $this->lastDoneOn = CarbonImmutable::today()->toDateString();
        $this->showForm = true;
    }

    public function editItem(string $itemId): void
    {
        $item = HealthCareItem::query()->findOrFail($itemId);

        $this->resetForm();
        $this->editingId = $item->id;
        $this->memberId = $item->member_id;
        $this->category = $item->category->value;
        $this->name = $item->name;
        $this->deviceName = (string) $item->device_name;
        $this->intervalValue = (string) $item->interval_value;
        $this->intervalUnit = $item->interval_unit->value;
        $this->lastDoneOn = $item->last_done_on?->toDateString() ?? '';
        $this->notes = (string) $item->notes;
        $this->showForm = true;
    }

    public function closeForm(): void
    {
        $this->showForm = false;
    }

    public function save(HealthCareItemService $service): void
    {
        $dados = $this->validate([
            'memberId' => ['required', 'string'],
            'category' => ['required', Rule::enum(HealthCareCategory::class)],
            'name' => ['required', 'string', 'max:120'],
            'deviceName' => ['nullable', 'string', 'max:120'],
            'intervalValue' => ['required', 'integer', 'min:1', 'max:365'],
            'intervalUnit' => ['required', Rule::enum(HealthCareIntervalUnit::class)],
            'lastDoneOn' => ['required', 'date', 'before_or_equal:today', 'after:2000-01-01'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ], attributes: [
            'memberId' => 'pessoa', 'category' => 'categoria', 'name' => 'item', 'deviceName' => 'aparelho',
            'intervalValue' => 'frequência', 'intervalUnit' => 'unidade', 'lastDoneOn' => 'última vez', 'notes' => 'observações',
        ]);

        $campos = [
            'category' => $dados['category'],
            'name' => $dados['name'],
            'device_name' => $dados['deviceName'],
            'interval_value' => $dados['intervalValue'],
            'interval_unit' => $dados['intervalUnit'],
            'last_done_on' => $dados['lastDoneOn'],
            'notes' => $dados['notes'],
        ];

        if ($this->editingId !== null) {
            $service->update(HealthCareItem::query()->findOrFail($this->editingId), $campos);
        } else {
            $service->create($this->membroOuFalha($dados['memberId']), $campos, $this->autor());
        }

        $this->showForm = false;
    }

    public function markDone(string $itemId, HealthCareItemService $service): void
    {
        $service->markDone(HealthCareItem::query()->findOrFail($itemId));
    }

    public function toggleActive(string $itemId, HealthCareItemService $service): void
    {
        $item = HealthCareItem::query()->findOrFail($itemId);
        $service->setActive($item, ! $item->is_active);
    }

    public function delete(string $itemId, HealthCareItemService $service): void
    {
        $service->delete(HealthCareItem::query()->findOrFail($itemId));

        if ($this->editingId === $itemId) {
            $this->showForm = false;
            $this->editingId = null;
        }
    }

    /** Próxima data mostrada ao vivo no formulário, antes de salvar. */
    public function getProximaPrevistaProperty(): ?CarbonImmutable
    {
        $valor = (int) $this->intervalValue;
        $unidade = HealthCareIntervalUnit::tryFrom($this->intervalUnit);

        if ($valor < 1 || $unidade === null || $this->lastDoneOn === '') {
            return null;
        }

        try {
            return $unidade->addTo(CarbonImmutable::parse($this->lastDoneOn), $valor);
        } catch (\Throwable) {
            return null;
        }
    }

    public function render()
    {
        $itens = HealthCareItem::query()
            ->where('profile_id', app(ProfileContext::class)->profileId())
            ->with('member')
            ->orderByRaw('next_due_on IS NULL')
            ->orderBy('next_due_on')
            ->get();

        return view('livewire.health.health-care-item-index', [
            'ativos' => $itens->where('is_active', true)->values(),
            'pausados' => $itens->where('is_active', false)->values(),
            'membros' => $this->membros(),
            'categorias' => HealthCareCategory::cases(),
            'unidades' => HealthCareIntervalUnit::cases(),
            'editingExisting' => $this->editingId !== null,
            'hoje' => CarbonImmutable::today(),
        ]);
    }

    private function membros(): Collection
    {
        return ProfileMember::query()
            ->where('profile_id', app(ProfileContext::class)->profileId())
            ->where('is_active', true)
            ->orderBy('role')
            ->get();
    }

    private function membroOuFalha(?string $memberId): ProfileMember
    {
        return ProfileMember::query()
            ->where('profile_id', app(ProfileContext::class)->profileId())
            ->where('is_active', true)
            ->findOrFail($memberId);
    }

    private function autor(): ProfileMember
    {
        return app(ProfileContext::class)->member();
    }

    private function resetForm(): void
    {
        $this->reset('editingId', 'memberId', 'category', 'name', 'deviceName', 'intervalValue', 'intervalUnit', 'lastDoneOn', 'notes');
        $this->category = 'hearing_aid';
        $this->intervalUnit = 'day';
        $this->resetErrorBag();
    }
}
