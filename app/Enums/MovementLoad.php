<?php

namespace App\Enums;

use App\Enums\Concerns\HasOptions;
use App\Support\GymExerciseClassifier;

/**
 * O que um exercício exige do corpo e uma limitação pode pedir para evitar.
 *
 * Como no treino automático, é inferência por palavras do NOME do exercício, e a tela diz isso: é uma sugestão por precaução, que a pessoa
 * confere (de preferência com o profissional). As listas ficam todas aqui para um fisioterapeuta ou profissional de educação física revisar
 * (ver docs/revisao-profissional-atividades.md).
 */
enum MovementLoad: string
{
    use HasOptions;

    case Impact = 'impact';
    case SpinalLoad = 'spinal_load';
    case Rotation = 'rotation';
    case Flexion = 'flexion';
    case Overhead = 'overhead';

    public function label(): string
    {
        return match ($this) {
            self::Impact => 'Impacto e saltos',
            self::SpinalLoad => 'Carga sobre a coluna',
            self::Rotation => 'Rotação do tronco com carga',
            self::Flexion => 'Inclinar o tronco com carga',
            self::Overhead => 'Peso acima da cabeça',
        };
    }

    /** @return list<string> palavras sem acento, em minúscula */
    public function keywords(): array
    {
        return match ($this) {
            self::Impact => ['salto', 'pular corda', 'jump', 'burpee', 'pliometr', 'arremesso', 'polichinelo', 'corrida', 'sprint'],
            self::SpinalLoad => ['agachamento livre', 'agachamento frontal', 'agachamento com barra', 'levantamento terra', 'terra romeno', 'stiff', 'good morning', 'bom dia', 'desenvolvimento', 'militar', 'remada curvada', 'cavalinho', 't-bar', 'panturrilha em pe', 'encolhimento', 'kettlebell swing', 'farmer', 'thruster', 'snatch', 'clean'],
            self::Rotation => ['rotacao do tronco', 'rotacao de tronco', 'russian twist', 'woodchop', 'wood chop', 'lenhador', 'arremesso', 'abdominal obliquo', 'twist'],
            self::Flexion => ['levantamento terra', 'terra romeno', 'stiff', 'good morning', 'bom dia', 'remada curvada', 'cavalinho', 't-bar', 'hiperextensao', 'kettlebell swing'],
            self::Overhead => ['desenvolvimento', 'militar', 'arnold', 'triceps frances', 'elevacao frontal', 'puxada atras', 'thruster', 'snatch', 'push press', 'pullover'],
        };
    }

    public function matches(string $nome): bool
    {
        $n = GymExerciseClassifier::normalize($nome);

        foreach ($this->keywords() as $palavra) {
            if (str_contains($n, $palavra)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Dentre os que a pessoa quer evitar, os que o nome indica.
     *
     * @param  list<self>  $evitar
     * @return list<self>
     */
    public static function hits(string $nome, array $evitar): array
    {
        return array_values(array_filter($evitar, fn (self $c) => $c->matches($nome)));
    }

    /** @param  array<int, mixed>|null  $valores */
    public static function fromValues(?array $valores): array
    {
        return array_values(array_filter(array_map(fn ($v) => is_string($v) ? self::tryFrom($v) : null, $valores ?? [])));
    }
}
