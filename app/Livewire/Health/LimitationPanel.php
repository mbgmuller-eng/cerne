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
use App\Models\ProfileMember;
use App\Services\Activities\LimitationService;
use App\Support\Forms\FormConsequence;
use App\Support\Health\HealthOrigins;
use App\Support\ProfileContext;
use Carbon\CarbonImmutable;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * As limitações físicas: dentro de um acompanhamento, ou na Ficha de Saúde (todas as da pessoa, inclusive as do passado). Mesma visibilidade
 * da Agenda de Saúde; os programas de atividades da pessoa leem daqui.
 */
class LimitationPanel extends Component
{
    use PicksHealthProfessional, RequiresPersonalHealth;

    /** Vazio = o painel é o da Ficha de Saúde, com todas as limitações da pessoa. */
    #[Locked]
    public string $followupId = '';

    #[Locked]
    public string $memberId = '';

    public bool $open = false;

    public string $editingId = '';

    public string $region = 'lower_back';

    public string $status = 'investigating';

    /** @var list<string> */
    public array $avoid = [];

    public string $guidance = '';

    public string $professional = '';

    public string $informedOn = '';

    public string $sinceYear = '';

    public string $origin = HealthOrigins::PAST;

    public function mount(?string $followupId = null, ?string $memberId = null, ?string $newFrom = null): void
    {
        $this->abortUnlessHealthParticipant();

        if ($followupId !== null && $followupId !== '') {
            $acompanhamento = HealthFollowup::query()->findOrFail($followupId);
            $this->followupId = $acompanhamento->id;
            $this->memberId = $acompanhamento->member_id;
        } else {
            $this->memberId = ProfileMember::query()->visibleHere()->findOrFail($memberId)->id;
        }

        // Veio de uma consulta ou de um exame: só ABRE o formulário. Nada é gravado sem a pessoa salvar.
        if ($newFrom !== null && app(ProfileContext::class)->canWriteAsCaregiver($this->memberId)) {
            $this->create();
            $this->origin = array_key_exists($newFrom, HealthOrigins::options($this->memberId)) ? $newFrom : HealthOrigins::PAST;
        }
    }

    public function create(): void
    {
        $this->resetErrorBag();
        $this->reset('editingId', 'region', 'status', 'guidance', 'professional', 'sinceYear');
        $this->avoid = $this->suggested($this->region);
        $this->informedOn = CarbonImmutable::today()->toDateString();
        $this->origin = $this->followupId !== '' ? 'followup:'.$this->followupId : HealthOrigins::PAST;
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
        $this->sinceYear = (string) $l->since_year;
        $this->origin = HealthOrigins::valueOf($l);
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
        return $this->memberId;
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
            'sinceYear' => ['nullable', 'integer', 'between:1900,'.now()->year],
        ], attributes: ['region' => 'região', 'status' => 'situação', 'guidance' => 'orientação', 'professional' => 'profissional', 'informedOn' => 'data', 'sinceYear' => 'ano']);

        $pessoa = ProfileMember::query()->visibleHere()->findOrFail($this->memberId);
        $dados = [
            'region' => $this->region, 'status' => $this->status, 'avoid' => $this->avoid, 'guidance' => $this->guidance,
            'professional' => $this->professional, 'informed_on' => $this->informedOn,
            'since_year' => $this->sinceYear !== '' ? (int) $this->sinceYear : null,
            'professional_id' => $this->resolveProfessional($pessoa->id, $this->professional),
        ];

        // No acompanhamento a ligação é com ele; na ficha, a pessoa escolhe de onde veio.
        if ($this->followupId === '') {
            $dados = array_merge($dados, HealthOrigins::columns($this->origin, $pessoa->id));
        }

        $service->save(
            $this->followupId !== '' ? $this->followup() : $pessoa,
            $dados, app(ProfileContext::class)->member(), $this->editingId !== '' ? $this->limitation($this->editingId) : null,
        );

        $this->open = false;
    }

    public function delete(string $id, LimitationService $service): void
    {
        $service->delete($this->limitation($id));
        $this->open = false;
    }

    /** Sempre dentro do que o painel mostra: o id vem do navegador. */
    private function limitation(string $id): PhysicalLimitation
    {
        return $this->query()->findOrFail($id);
    }

    private function query()
    {
        return PhysicalLimitation::query()->where('member_id', $this->memberId)
            ->when($this->followupId !== '', fn ($q) => $q->where('followup_id', $this->followupId));
    }

    private function followup(): HealthFollowup
    {
        return HealthFollowup::query()->findOrFail($this->followupId);
    }

    public function render()
    {
        $contexto = app(ProfileContext::class);
        // Os programas de atividades são só da própria pessoa: o atalho só aparece para ela. O espaço de uma pessoa acompanhada não tem a área.
        $propria = ! $contexto->isConsultant() && $contexto->memberId() !== null && $contexto->memberId() === $this->memberId
            && $contexto->profile()?->profile_type !== ProfileType::Care;

        return view('livewire.health.limitation-panel', [
            'limitacoes' => $this->query()->with('createdByUser')->orderBy('created_at')->get(),
            'regioes' => BodyRegion::options(),
            'situacoes' => LimitationStatus::options(),
            'cargas' => MovementLoad::options(),
            'naFicha' => $this->followupId === '',
            'origens' => $this->open && $this->followupId === '' ? HealthOrigins::options($this->memberId) : [],
            'propria' => $propria,
            'campoProfissional' => $this->open ? $this->professionalField($this->memberId, $this->professional) : null,
            'consequencia' => $this->open ? FormConsequence::physicalLimitation($propria) : [],
        ]);
    }
}
