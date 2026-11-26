<?php

namespace App\Livewire\Activities;

use App\Enums\ActivityRecordStatus;
use App\Enums\ActivityType;
use App\Livewire\Activities\Concerns\ActivityScreen;
use App\Models\ActivityRecord;
use App\Services\Activities\ActivityService;
use App\Services\Activities\ActivityWeekService;
use App\Support\Forms\FormConsequence;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Component;

/** O diário: uma atividade, ou vários dias de uma vez num período. */
#[Layout('components.layouts.app')]
class ActivityLog extends Component
{
    use ActivityScreen;

    /** 'one' | 'many' */
    public string $mode = 'one';

    public string $type = 'tennis';

    public string $customName = '';

    public string $date = '';

    public string $minutes = '60';

    public string $distance = '';

    public string $heartRate = '';

    public ?int $effort = 6;

    public ?int $pain = null;

    public string $note = '';

    /** @var list<int|string> dias da semana do lançamento em lote (0 = segunda) */
    public array $weekdays = [];

    public string $from = '';

    public string $until = '';

    /** @var array<string, bool>|null data => entra ou não; nulo = a lista ainda não foi montada */
    public ?array $days = null;

    /** A pessoa já foi avisada de que há um registro igual e quer salvar assim mesmo. */
    public bool $confirmDuplicate = false;

    public int $limit = 15;

    public function mount(): void
    {
        $this->guardActivityScreen();
        $hoje = CarbonImmutable::today();
        $this->date = $hoje->toDateString();
        $this->from = $hoje->subMonths(2)->toDateString();
        $this->until = $hoje->toDateString();
    }

    public function updated(string $campo): void
    {
        // Mudou o que define o registro: o aviso de repetido e a lista de dias deixam de valer.
        if (in_array($campo, ['type', 'customName', 'date', 'from', 'until', 'weekdays'], true) || str_starts_with($campo, 'weekdays.')) {
            $this->confirmDuplicate = false;
            $this->days = null;
        }
    }

    public function setMode(string $modo): void
    {
        $this->mode = $modo === 'many' ? 'many' : 'one';
        $this->days = null;
        $this->confirmDuplicate = false;
        $this->resetErrorBag();
    }

    public function save(ActivityService $activities): void
    {
        $this->validate($this->commonRules() + [
            'date' => ['required', 'date', 'before_or_equal:today', 'after:2000-01-01'],
            'distance' => ['nullable', 'string', 'max:8', 'regex:/^\d{1,4}([.,]\d{1,2})?$/'],
            'heartRate' => ['nullable', 'integer', 'between:30,230'],
            'pain' => ['nullable', 'integer', 'between:0,10'],
            'note' => ['nullable', 'string', 'max:500'],
        ], $this->messages(), $this->names());

        $tipo = ActivityType::from($this->type);

        if (! $this->confirmDuplicate && $activities->exists($tipo, $this->customName, $this->date)) {
            $this->confirmDuplicate = true;

            return;
        }

        $km = trim($this->distance) !== '' ? (float) str_replace(',', '.', $this->distance) : null;

        $activities->record([
            'activity' => $this->type, 'custom_name' => $this->customName, 'done_on' => $this->date, 'minutes' => (int) $this->minutes,
            'effort' => $this->effort, 'pain' => $this->pain, 'distance_meters' => $km !== null ? (int) round($km * 1000) : null,
            'heart_rate' => $this->heartRate !== '' ? (int) $this->heartRate : null, 'note' => $this->note,
        ]);

        $this->reset('distance', 'heartRate', 'pain', 'note', 'confirmDuplicate');
        session()->flash('status', 'Atividade registrada.');
    }

    public function buildDays(ActivityService $activities): void
    {
        $this->validate($this->commonRules() + [
            'from' => ['required', 'date', 'after:2000-01-01', 'before_or_equal:today'],
            'until' => ['required', 'date', 'after_or_equal:from'],
            'weekdays' => ['required', 'array', 'min:1'],
            'weekdays.*' => ['integer', 'between:0,6'],
        ], $this->messages(), $this->names());

        $datas = $activities->datesBetween($this->from, $this->until, $this->weekdays);
        $tipo = ActivityType::from($this->type);

        // O que já está no diário vem desmarcado, para não entrar em dobro.
        $this->days = [];
        foreach ($datas as $data) {
            $this->days[$data] = ! $activities->exists($tipo, $this->customName, $data);
        }
    }

    public function toggleDay(string $data): void
    {
        if (isset($this->days[$data])) {
            $this->days[$data] = ! $this->days[$data];
        }
    }

    public function saveDays(ActivityService $activities): void
    {
        $this->validate($this->commonRules(), $this->messages(), $this->names());

        // As datas voltam do navegador: só valem as que o período e os dias escolhidos geram.
        $validas = $activities->datesBetween($this->from, $this->until, $this->weekdays);
        $marcadas = array_values(array_intersect(array_keys(array_filter($this->days ?? [])), $validas));

        if ($marcadas === []) {
            $this->addError('days', 'Marque ao menos um dia.');

            return;
        }

        $total = $activities->recordBatch(['activity' => $this->type, 'custom_name' => $this->customName, 'minutes' => (int) $this->minutes, 'effort' => $this->effort], $marcadas);
        $this->days = null;
        session()->flash('status', $total === 1 ? '1 registro salvo.' : $total.' registros salvos.');
    }

    public function delete(string $recordId, ActivityService $activities): void
    {
        try {
            $activities->delete(ActivityRecord::query()->findOrFail($recordId));
        } catch (DomainException $e) {
            $this->addError('list', $e->getMessage());
        }
    }

    public function more(): void
    {
        $this->limit = min(300, $this->limit + 30);
    }

    /** @return array<string, mixed> */
    private function commonRules(): array
    {
        return [
            'type' => ['required', Rule::in(ActivityType::values())],
            'customName' => [$this->type === 'other' ? 'required' : 'nullable', 'string', 'max:60'],
            'minutes' => ['required', 'integer', 'between:1,600'],
            'effort' => ['nullable', 'integer', 'between:0,10'],
        ];
    }

    /** @return array<string, string> */
    private function messages(): array
    {
        return [
            'customName.required' => 'Diga o nome da atividade.',
            'weekdays.required' => 'Marque ao menos um dia da semana.',
            'distance.regex' => 'Informe a distância em quilômetros, como 5 ou 5,2.',
            'date.before_or_equal' => 'O diário é do que já aconteceu. Para o futuro, marque em Minha semana.',
            'until.after_or_equal' => 'O fim do período vem depois do começo.',
        ];
    }

    /** @return array<string, string> */
    private function names(): array
    {
        return ['type' => 'atividade', 'customName' => 'nome', 'date' => 'data', 'minutes' => 'duração', 'effort' => 'esforço', 'pain' => 'dor', 'distance' => 'distância', 'heartRate' => 'batimento médio', 'note' => 'observação', 'from' => 'início', 'until' => 'fim'];
    }

    private function activityName(): string
    {
        $tipo = ActivityType::tryFrom($this->type) ?? ActivityType::Other;

        return $tipo === ActivityType::Other ? (trim($this->customName) ?: 'A atividade') : $tipo->label();
    }

    public function render(ActivityService $activities)
    {
        $activities->syncGymSessions(CarbonImmutable::today()->subMonths(3), CarbonImmutable::today());

        $consulta = ActivityRecord::query()->where('status', ActivityRecordStatus::Done->value);
        $total = (clone $consulta)->count();
        $tipo = ActivityType::tryFrom($this->type) ?? ActivityType::Other;
        $nestaSemana = $this->date !== '' && $this->date >= ActivityWeekService::weekStart(CarbonImmutable::today())->toDateString();

        return view('livewire.activities.activity-log', [
            'tipos' => ActivityType::choices(),
            'comDistancia' => $tipo->hasDistance(),
            'dias' => ActivityWeekService::DAYS,
            'nome' => $this->activityName(),
            'registros' => $consulta->orderByDesc('done_on')->orderByDesc('created_at')->limit($this->limit)->get(),
            'total' => $total,
            'consequencia' => FormConsequence::activityRecord($this->activityName(), (int) $this->minutes, $this->effort, $nestaSemana),
            'consequenciaLote' => $this->days !== null ? FormConsequence::activityBatch($this->activityName(), count(array_filter($this->days)), (int) $this->minutes, $this->effort) : [],
        ]);
    }
}
