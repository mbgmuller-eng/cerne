<?php

namespace App\Support\Activities;

/**
 * Os passos do programa de caminhada e corrida: caminhada, depois trote alternado com caminhada, até o trote seguido. Regras fixas, sem IA,
 * e o programa parte do que a pessoa consegue HOJE, não do que já correu.
 *
 * Orientação geral, não prescrição. Os passos estão aqui, num lugar só, para um profissional de educação física revisar
 * (ver docs/revisao-profissional-atividades.md).
 */
final class RunningLadder
{
    /** Caminhada de aquecimento no começo de toda sessão, em minutos. */
    public const WARM_UP_MINUTES = 5;

    /** @var list<array{0: int, 1: int, 2: int}> [repetições, minutos de trote, minutos de caminhada] */
    private const STEPS = [
        [1, 0, 25],
        [6, 1, 2],
        [8, 1, 2],
        [6, 2, 2],
        [6, 3, 2],
        [4, 5, 2],
        [3, 8, 2],
        [2, 12, 2],
        [1, 25, 0],
    ];

    /** A resposta de "quanto trote confortável, sem dor, você faz hoje?" => o passo em que o programa começa. */
    private const START_BY_TEST = [0 => 0, 1 => 1, 2 => 3, 3 => 5];

    public const TESTS = [0 => 'Nenhum, começo andando', 1 => '1 a 2 minutos', 2 => '3 a 5 minutos', 3 => '10 minutos ou mais'];

    public static function last(): int
    {
        return count(self::STEPS) - 1;
    }

    public static function clamp(int $passo): int
    {
        return max(0, min(self::last(), $passo));
    }

    public static function startFor(int $teste): int
    {
        return self::START_BY_TEST[$teste] ?? 0;
    }

    public static function describe(int $passo): string
    {
        [$vezes, $trote, $caminhada] = self::STEPS[self::clamp($passo)];

        if ($trote === 0) {
            return "{$caminhada} min de caminhada";
        }

        if ($caminhada === 0) {
            return "{$trote} min de trote seguidos";
        }

        return "{$vezes} vezes: {$trote} min de trote e {$caminhada} min de caminhada";
    }

    /** @return list<string> */
    public static function all(): array
    {
        return array_map(fn (int $i) => self::describe($i), array_keys(self::STEPS));
    }

    /** Duração da sessão, com o aquecimento. */
    public static function minutes(int $passo): int
    {
        [$vezes, $trote, $caminhada] = self::STEPS[self::clamp($passo)];

        return self::WARM_UP_MINUTES + $vezes * ($trote + $caminhada);
    }

    public static function isWalkingOnly(int $passo): bool
    {
        return self::STEPS[self::clamp($passo)][1] === 0;
    }
}
