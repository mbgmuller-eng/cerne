<?php

namespace App\Livewire\Health;

use App\Enums\BodyRegion;
use App\Enums\LimitationStatus;
use App\Enums\MovementLoad;
use App\Enums\ProfileType;
use App\Livewire\Concerns\RequiresPersonalHealth;
use App\Livewire\Health\Concerns\PicksHealthProfessional;
use App\Models\HealthFollowup;
use App\Models\HealthProfessional;
use App\Models\PhysicalLimitation;
use App\Services\Activities\LimitationService;
use App\Support\Forms\FormConsequence;
use App\Support\ProfileContext;
use Carbon\CarbonImmutable;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Locked;
use Livewire\Component;

/** As limitações físicas de um acompanhamento. Mesma visibilidade da Agenda de Saúde; os programas de atividades da pessoa leem daqui. */
class LimitationPanel extends Component
{
    use PicksHealthProfessional, RequiresPersonalHealth;

    #[Locked]
    public string $followupId = '';

    public bool $open = false;

    public string $editingId = '';

    public string $region = 'lower_back';

    public string $status = 'investigating';

    /** @var list<string> */
    public array $avoid = [];

    public string $guidance = '';

    public string $professional = '';

    public string $informedOn = '';

    public function mount(string $followupId): void
    {
        $this->abortUnlessHealthParticipant();
        $this->followupId = HealthFollowup::query()->findOrFail($followupId)->id;
    }

    public function create(): void
    {
        $this->resetErrorBag();
        $this->reset('editingId', 'region', 'status', 'guidance', 'professional');
        $this->avoid = $this->suggested($this->region);
        $this->informedOn = CarbonImmutable::today()->toDateString();
        $this->resetProfessional();
        $this->open = true;
    }

    public function edit(string $id): void
    {
        $l = $this->limitation($id);
        $this->resetErrorBag();
        $this->editingId = $l->id;
        $this->region = $l->bodyRegion()?->value ?? 'lower_back';
        $this->status = $l->status->value;
        $this->avoid = array_map(fn (MovementLoad $c) => $c->value, $l->avoidLoads());
        $this->guidance = (string) $l->guidance;
        $this->professional = (string) $l->professional;
        $this->informedOn = $l->informed_on?->toDateString() ?? '';
        $this->resetProfessional($l->professional_id, (string) $l->professional === '');
        $this->open = true;
    }

    /** Numa limitação nova, trocar a região troca o que vem marcado; numa que já existe, não mexe no que a pessoa escolheu. */
    public function updatedRegion(string $valor): void
    {
        if ($this->editingId === '') {
            $this->avoid = $this->suggested($valor);
        }
    }

    /** @return list<string> */
    private function suggested(string $regiao): array
    {
        return array_map(fn (MovementLoad $c) => $c->value, BodyRegion::tryFrom($regiao)?->suggestedAvoid() ?? []);
    }

    protected function professionalPersonId(): string
    {
        return $this->followup()->member_id;
    }

    protected function applyProfessional(HealthProfessional $profissional): void
    {
        $this->professional = $profissional->name;
    }

    public function save(LimitationService $service): void
    {
        $this->validate([
            'region' => ['required', Rule::in(BodyRegion::values())],
            'status' => ['required', Rule::in(LimitationStatus::values())],
            'avoid' => ['array'],
            'avoid.*' => [Rule::in(MovementLoad::values())],
            'guidance' => ['nullable', 'string', 'max:2000'],
            'professional' => ['nullable', 'string', 'max:160'],
            'informedOn' => ['nullable', 'date', 'before_or_equal:today', 'after:1900-01-01'],
        ], attributes: ['region' => 'região', 'status' => 'situação', 'guidance' => 'orientação', 'professional' => 'profissional', 'informedOn' => 'data']);

        $acompanhamento = $this->followup();

        $service->save($acompanhamento, [
            'region' => $this->region, 'status' => $this->status, 'avoid' => $this->avoid, 'guidance' => $this->guidance,
            'professional' => $this->professional, 'informed_on' => $this->informedOn,
            'professional_id' => $this->resolveProfessional($acompanhamento->member_id, $this->professional),
        ], app(ProfileContext::class)->member(), $this->editingId !== '' ? $this->limitation($this->editingId) : null);

        $this->open = false;
    }

    public function delete(string $id, LimitationService $service): void
    {
        $service->delete($this->limitation($id));
        $this->open = false;
    }

    /** Sempre dentro do acompanhamento aberto: o id vem do navegador. */
    private function limitation(string $id): PhysicalLimitation
    {
        return PhysicalLimitation::query()->where('followup_id', $this->followupId)->findOrFail($id);
    }

    private function followup(): HealthFollowup
    {
        return HealthFollowup::query()->findOrFail($this->followupId);
    }

    public function render()
    {
        $acompanhamento = $this->followup();
        $contexto = app(ProfileContext::class);
        // Os programas de atividades são só da própria pessoa: o atalho só aparece para ela. O espaço de uma pessoa acompanhada não tem a área.
        $propria = ! $contexto->isConsultant() && $contexto->memberId() !== null && $contexto->memberId() === $acompanhamento->member_id
            && $contexto->profile()?->profile_type !== ProfileType::Care;

        return view('livewire.health.limitation-panel', [
            'limitacoes' => PhysicalLimitation::query()->where('followup_id', $this->followupId)->with('createdByUser')->orderBy('created_at')->get(),
            'regioes' => BodyRegion::options(),
            'situacoes' => LimitationStatus::options(),
            'cargas' => MovementLoad::options(),
            'propria' => $propria,
            'podeEscrever' => $contexto->canWriteAsCaregiver($acompanhamento->member_id),
            'campoProfissional' => $this->open ? $this->professionalField($acompanhamento->member_id, $this->professional) : null,
            'consequencia' => $this->open ? FormConsequence::physicalLimitation($propria) : [],
        ]);
    }
}
