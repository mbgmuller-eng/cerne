<?php

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

/**
 * Como a carga de uma série foi digitada. A mesma carga aparece de jeitos
 * diferentes na academia ("15 kg cada lado", "14 kg cada halter", "6
 * barras" na flexora), e o que o usuário digitou precisa ser guardado como
 * digitou — o total em kg é derivado, quando dá pra derivar (ver totalKg()).
 */
enum GymLoadMode: string
{
    use HasOptions;

    case Total = 'total';
    case PerSide = 'per_side';
    case PerDumbbell = 'per_dumbbell';
    case Bodyweight = 'bodyweight';
    case BodyweightPlus = 'bodyweight_plus';
    case StackPosition = 'stack_position';

    public function label(): string
    {
        return match ($this) {
            self::Total => 'Total',
            self::PerSide => 'Cada lado',
            self::PerDumbbell => 'Cada halter',
            self::Bodyweight => 'Peso do corpo',
            self::BodyweightPlus => 'Peso do corpo + carga',
            self::StackPosition => 'Posição da máquina',
        };
    }

    /**
     * Carga EXTERNA total em kg, ou null quando não há como converter.
     *
     * "Cada lado" e "cada halter" dobram, sem contar o peso da barra
     * (agachamento com 15 kg de cada lado = 30 kg). Exercício unilateral
     * (remada com um halter) usa o modo Total. Peso do corpo puro é 0 de
     * carga externa; "6 barras" da máquina não tem equivalente em kg — a
     * pesagem varia de máquina pra máquina.
     *
     * bcmath com escala 2, como o resto do app faz com números que não
     * podem passar por float.
     */
    public function totalKg(?string $value): ?string
    {
        return match ($this) {
            self::Bodyweight => '0.00',
            self::StackPosition => null,
            self::Total, self::BodyweightPlus => $value === null ? null : bcadd($value, '0', 2),
            self::PerSide, self::PerDumbbell => $value === null ? null : bcmul($value, '2', 2),
        };
    }
}
