<?php

namespace App\Livewire\Health;

use App\Livewire\Concerns\RequiresPersonalHealth;
use App\Models\HealthCard;
use App\Models\HealthMeasurement;
use App\Models\ProfileMember;
use App\Services\HealthMeasurementService;
use App\Support\Charts\HealthCharts;
use App\Support\Forms\FormConsequence;
use App\Support\Health\BodyMass;
use App\Support\ProfileContext;
use Carbon\CarbonImmutable;
use DomainException;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * Peso, altura e cintura de uma pessoa, na Ficha de Saúde: histórico, IMC, a faixa de referência, a meta (só da própria pessoa) e o que ela
 * escolhe mostrar aos que veem a ficha e no QR de emergência.
 */
class MeasurementsPanel extends Component
{
    use RequiresPersonalHealth;

    #[Locked]
    public string $memberId = '';

    public bool $adding = false;

    public bool $settingGoal = false;

    public string $date = '';

    public string $weight = '';

    public string $height = '';

    public string $waist = '';

    public string $goal = '';

    public bool $share = false;

    public bool $qr = false;

    public bool $qrLimitations = false;

    public function mount(string $memberId): void
    {
        $this->abortUnlessHealthParticipant();
        $this->memberId = ProfileMember::query()->visibleHere()->findOrFail($memberId)->id;
        $this->loadSharing();
    }

    private function loadSharing(): void
    {
        $ficha = HealthCard::query()->where('member_id', $this->memberId)->first();
        $this->share = (bool) $ficha?->share_measurements;
        $this->qr = (bool) $ficha?->qr_measurements;
        $this->qrLimitations = (bool) $ficha?->qr_limitations;
    }

    public function startAdding(HealthMeasurementService $service): void
    {
        abort_unless(HealthMeasurement::canWriteFor($this->memberId), 403);

        $atual = $service->current($service->history($this->memberId), $this->member());
        $this->resetErrorBag();
        $this->reset('weight', 'waist');
        $this->date = CarbonImmutable::today()->toDateString();
        // A altura quase não muda: já vem a última, para a pessoa só conferir.
        $this->height = $atual['height'] !== null ? (string) $atual['height'] : '';
        $this->adding = true;
        $this->settingGoal = false;
    }

    public function save(HealthMeasurementService $service): void
    {
        $this->validate([
            'date' => ['required', 'date', 'before_or_equal:today', 'after:1900-01-01'],
            'weight' => ['nullable', 'string', 'max:6', 'regex:/^\d{2,3}([.,]\d)?$/'],
            'height' => ['nullable', 'integer', 'between:50,250'],
            'waist' => ['nullable', 'integer', 'between:30,250'],
        ], ['weight.regex' => 'Informe o peso em quilos, como 84,5.'], ['date' => 'data', 'weight' => 'peso', 'height' => 'altura', 'waist' => 'cintura']);

        $peso = trim($this->weight) !== '' ? BodyMass::parse($this->weight) : null;

        if ($peso !== null && ($peso < 20 || $peso > 400)) {
            $this->addError('weight', 'Confira o peso informado.');

            return;
        }

        $atual = $service->current($service->history($this->memberId), $this->member());
        $altura = $this->height !== '' ? (int) $this->height : null;

        try {
            $service->add($this->member(), [
                'measured_on' => $this->date,
                'weight' => $peso,
                // A altura que só foi confirmada (igual à última) não vira uma nova medida.
                'height' => $altura !== null && ($altura !== $atual['height'] || $peso === null) ? $altura : null,
                'waist' => $this->waist !== '' ? (int) $this->waist : null,
            ], app(ProfileContext::class)->member());
        } catch (DomainException $e) {
            $this->addError('weight', $e->getMessage());

            return;
        }

        $this->adding = false;
    }

    public function delete(string $id, HealthMeasurementService $service): void
    {
        $service->remove(HealthMeasurement::query()->where('member_id', $this->memberId)->findOrFail($id));
    }

    public function startGoal(): void
    {
        $this->resetErrorBag();
        $this->goal = '';
        $this->settingGoal = true;
        $this->adding = false;
    }

    public function saveGoal(HealthMeasurementService $service): void
    {
        $kg = BodyMass::parse($this->goal);

        if ($kg === null) {
            $this->addError('goal', 'Informe só o número, como 79.');

            return;
        }

        try {
            $service->setGoal($this->member(), $kg);
        } catch (DomainException $e) {
            $this->addError('goal', $e->getMessage());

            return;
        }

        $this->settingGoal = false;
    }

    public function clearGoal(HealthMeasurementService $service): void
    {
        $service->clearGoal($this->member());
    }

    public function updated(string $campo, HealthMeasurementService $service): void
    {
        if (! in_array($campo, ['share', 'qr', 'qrLimitations'], true)) {
            return;
        }

        try {
            $service->setSharing($this->member(), $this->share, $this->qr, $this->qrLimitations);
        } catch (DomainException) {
            abort(403);
        }
    }

    private function member(): ProfileMember
    {
        return ProfileMember::query()->visibleHere()->findOrFail($this->memberId);
    }

    public function render(HealthMeasurementService $service)
    {
        $membro = $this->member();
        $escreve = HealthMeasurement::canWriteFor($this->memberId);
        $compartilhado = (bool) HealthCard::query()->where('member_id', $this->memberId)->value('share_measurements');
        $contexto = app(ProfileContext::class);
        $visivel = $escreve || $compartilhado || (bool) $contexto->profile()?->isCare();
        $historico = $visivel ? $service->history($this->memberId) : collect();
        $atual = $service->current($historico, $membro);
        $meta = $service->goal($this->memberId);
        $pesagens = $historico->filter(fn (HealthMeasurement $m) => $m->weight() !== null)->values();
        $tendencia = HealthMeasurementService::trend($pesagens->map(fn (HealthMeasurement $m) => $m->weight())->all());
        $propria = $contexto->memberId() !== null && $contexto->memberId() === $this->memberId;

        return view('livewire.health.measurements-panel', [
            'visivel' => $visivel,
            'escreve' => $escreve,
            'propria' => $propria,
            'cuidado' => (bool) $contexto->profile()?->isCare(),
            'atual' => $atual,
            'historico' => $historico->sortByDesc(fn (HealthMeasurement $m) => $m->measured_on->format('Y-m-d').$m->created_at)->values(),
            'meta' => $meta,
            'grafico' => HealthCharts::weight(
                $pesagens->map(fn (HealthMeasurement $m, int $i) => ['label' => $m->measured_on->format('d/m/y'), 'value' => $m->weight(), 'trend' => $tendencia[$i]])->all(),
                $atual['range'], $meta['kg'] ?? null, 'Histórico de peso com tendência',
            ),
            'idoso' => BodyMass::isSenior($atual['age']),
            'consequencia' => $this->adding ? FormConsequence::measurement($compartilhado, (bool) $this->qr) : [],
            'consequenciaMeta' => $this->settingGoal ? FormConsequence::weightGoal() : [],
        ]);
    }
}
