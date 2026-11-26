<?php

namespace App\Services;

use App\Models\ActivityProfile;
use App\Models\HealthCard;
use App\Models\HealthMeasurement;
use App\Models\ProfileMember;
use App\Services\Activities\ActivityService;
use App\Support\Health\BodyMass;
use App\Support\ProfileContext;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Support\Collection;

/**
 * Peso, altura e cintura com histórico, o IMC, a meta de peso (só da própria pessoa) e o que ela escolhe mostrar. Tudo é cifrado, então a
 * conta é feita no PHP; o volume por pessoa é pequeno.
 */
class HealthMeasurementService
{
    public function __construct(private readonly HealthCardService $cards, private readonly ActivityService $activities) {}

    /** @return Collection<int, HealthMeasurement> da mais antiga para a mais nova; vazia para quem não pode ver */
    public function history(string $membroId): Collection
    {
        return HealthMeasurement::query()->where('member_id', $membroId)->orderBy('measured_on')->orderBy('created_at')->get();
    }

    /**
     * O que vale hoje: o último peso e a última altura informados (podem estar em medidas diferentes).
     *
     * @param  Collection<int, HealthMeasurement>  $historico
     * @return array{weight: ?float, weight_on: ?CarbonImmutable, height: ?int, waist: ?int, bmi: ?float, category: ?string, range: ?array{0: float, 1: float}, age: ?int}
     */
    public function current(Collection $historico, ?ProfileMember $membro): array
    {
        $comPeso = $historico->last(fn (HealthMeasurement $m) => $m->weight() !== null);
        $altura = $historico->last(fn (HealthMeasurement $m) => $m->height() !== null)?->height();
        $cintura = $historico->last(fn (HealthMeasurement $m) => is_numeric($m->waist_cm))?->waist_cm;
        $idade = $membro?->birthdate !== null ? (int) $membro->birthdate->diffInYears(now()) : null;
        $imc = $comPeso !== null && $altura !== null && BodyMass::applies($idade) ? BodyMass::bmi($comPeso->weight(), $altura) : null;

        return [
            'weight' => $comPeso?->weight(),
            'weight_on' => $comPeso?->measured_on?->toImmutable(),
            'height' => $altura,
            'waist' => $cintura !== null ? (int) $cintura : null,
            'bmi' => $imc,
            'category' => $imc !== null ? BodyMass::category($imc, $idade) : null,
            'range' => $altura !== null && BodyMass::applies($idade) ? BodyMass::weightRange($altura, $idade) : null,
            'age' => $idade,
        ];
    }

    /**
     * A tendência: a média das 3 últimas pesagens, porque o peso oscila de um dia para o outro.
     *
     * @param  list<float>  $pesos
     * @return list<float>
     */
    public static function trend(array $pesos): array
    {
        $saida = [];

        foreach (array_keys($pesos) as $i) {
            $janela = array_slice($pesos, max(0, $i - 2), min($i + 1, 3));
            $saida[] = round(array_sum($janela) / count($janela), 1);
        }

        return $saida;
    }

    /** @param  array{measured_on: string, weight?: ?float, height?: ?int, waist?: ?int}  $d */
    public function add(ProfileMember $membro, array $d, ?ProfileMember $autor): HealthMeasurement
    {
        if (($d['weight'] ?? null) === null && ($d['height'] ?? null) === null && ($d['waist'] ?? null) === null) {
            throw new DomainException('Informe ao menos o peso, a altura ou a cintura.');
        }

        return HealthMeasurement::create([
            'member_id' => $membro->id,
            'measured_on' => $d['measured_on'],
            'weight_kg' => isset($d['weight']) ? number_format((float) $d['weight'], 1, '.', '') : null,
            'height_cm' => isset($d['height']) ? (string) (int) $d['height'] : null,
            'waist_cm' => isset($d['waist']) ? (string) (int) $d['waist'] : null,
            'created_by_member_id' => $autor?->id,
        ]);
    }

    public function remove(HealthMeasurement $medida): void
    {
        $medida->delete();
    }

    /** O que a pessoa mostra: aos que veem a ficha e no QR de emergência. Só ela muda (o model recusa os outros). */
    public function setSharing(ProfileMember $membro, bool $compartilhar, bool $noQr, bool $limitacoesNoQr): HealthCard
    {
        if (! HealthMeasurement::canWriteFor($membro->id)) {
            throw new DomainException('Só a própria pessoa escolhe o que mostrar.');
        }

        $ficha = $this->cards->cardFor($membro);
        $ficha->update(['share_measurements' => $compartilhar, 'qr_measurements' => $noQr, 'qr_limitations' => $limitacoesNoQr]);

        return $ficha;
    }

    // --- Meta de peso: só da própria pessoa (mora no perfil de atividades, que ninguém mais vê) -----------------------

    /** A meta de quem está olhando, ou nulo. */
    public function goal(string $membroId): ?array
    {
        if (app(ProfileContext::class)->memberId() !== $membroId) {
            return null;
        }

        $perfil = ActivityProfile::query()->first();

        return $perfil !== null && is_numeric($perfil->weight_goal)
            ? ['kg' => (float) $perfil->weight_goal, 'start' => is_numeric($perfil->weight_goal_start) ? (float) $perfil->weight_goal_start : null, 'since' => $perfil->weight_goal_set_on]
            : null;
    }

    /** A meta não pode ficar abaixo da faixa de referência pelo IMC, e quem já está abaixo dela não recebe meta de perda. */
    public function setGoal(ProfileMember $membro, float $kg): void
    {
        if (app(ProfileContext::class)->memberId() !== $membro->id) {
            throw new DomainException('A meta de peso é só da própria pessoa.');
        }

        $atual = $this->current($this->history($membro->id), $membro);

        if ($atual['weight'] === null || $atual['range'] === null) {
            throw new DomainException('Registre antes o seu peso e a sua altura.');
        }

        [$minimo] = $atual['range'];

        if ($atual['weight'] < $minimo) {
            throw new DomainException('O seu peso está abaixo da faixa de referência. O Cerne não oferece meta de perda: converse com um profissional.');
        }

        if ($kg < $minimo) {
            throw new DomainException('A meta não pode ficar abaixo da faixa de referência ('.BodyMass::format($minimo).' kg). Se um profissional indicou outro valor, converse com ele.');
        }

        if ($kg >= $atual['weight']) {
            throw new DomainException('Esta meta é para perda de peso: informe um valor menor que o atual.');
        }

        $this->saveGoal(['weight_goal' => number_format($kg, 1, '.', ''), 'weight_goal_start' => number_format($atual['weight'], 1, '.', ''), 'weight_goal_set_on' => CarbonImmutable::today()->toDateString()]);
    }

    public function clearGoal(ProfileMember $membro): void
    {
        if (app(ProfileContext::class)->memberId() !== $membro->id) {
            throw new DomainException('A meta de peso é só da própria pessoa.');
        }

        $this->saveGoal(['weight_goal' => null, 'weight_goal_start' => null, 'weight_goal_set_on' => null]);
    }

    /** @param  array<string, ?string>  $dados */
    private function saveGoal(array $dados): void
    {
        $perfil = ActivityProfile::query()->first();

        if ($perfil === null) {
            $padrao = $this->activities->profile();
            $perfil = $this->activities->saveProfile((array) $padrao->goals, (array) $padrao->gym_days, (int) $padrao->session_minutes, (bool) $padrao->consider_limitations);
        }

        $perfil->forceFill($dados)->save();
    }
}
