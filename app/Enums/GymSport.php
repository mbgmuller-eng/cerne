<?php

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

/**
 * Esporte ou atividade que a academia reforça. O esporte não é um objetivo a mais: o objetivo diz como o treino é prescrito (séries,
 * repetições, descanso) e o esporte diz QUAIS exercícios ganham prioridade, que espaços extras entram e o que a pessoa deve cuidar.
 *
 * As palavras de `emphasis()` são comparadas com o nome do exercício sem acento e em minúscula (GymExerciseClassifier::normalize).
 */
enum GymSport: string
{
    use HasOptions;

    case Running = 'running';
    case Cycling = 'cycling';
    case Swimming = 'swimming';
    case Racket = 'racket';
    case Soccer = 'soccer';
    case Court = 'court';
    case MartialArts = 'martial_arts';

    public function label(): string
    {
        return match ($this) {
            self::Running => 'Corrida',
            self::Cycling => 'Ciclismo',
            self::Swimming => 'Natação',
            self::Racket => 'Tênis, padel e outros esportes de raquete',
            self::Soccer => 'Futebol',
            self::Court => 'Vôlei e basquete',
            self::MartialArts => 'Lutas e artes marciais',
        };
    }

    /** Para o nome da fase, onde o rótulo completo ficaria comprido. */
    public function shortLabel(): string
    {
        return match ($this) {
            self::Racket => 'esportes de raquete',
            self::MartialArts => 'lutas',
            default => mb_strtolower($this->label()),
        };
    }

    public function hint(): string
    {
        return match ($this) {
            self::Running => 'Força de perna unilateral, glúteos, panturrilha e core para correr com mais eficiência e menos lesão.',
            self::Cycling => 'Pernas e glúteos com carga, core estável e costas para aguentar a posição na bike.',
            self::Swimming => 'Costas e ombros fortes e equilibrados, core firme e proteção do ombro.',
            self::Racket => 'Rotação do tronco, ombro estável, antebraço e pernas rápidas, com trabalho de um lado de cada vez.',
            self::Soccer => 'Pernas fortes, posteriores e adutores protegidos, glúteos e core para arrancadas e mudanças de direção.',
            self::Court => 'Pernas e panturrilha para saltar, ombro protegido, costas e core.',
            self::MartialArts => 'Puxadas, rotação do tronco, pegada forte e pernas resistentes.',
        };
    }

    /** O objetivo de academia que costuma servir a quem pratica o esporte; é só a sugestão inicial, a pessoa muda. */
    public function defaultGoal(): GymGoal
    {
        return match ($this) {
            self::Running, self::Cycling, self::Soccer, self::Court => GymGoal::Strength,
            self::Swimming, self::Racket, self::MartialArts => GymGoal::GeneralHealth,
        };
    }

    /** Esporte de resistência: ele mesmo já é o condicionamento, então o treino não soma bloco de cardio. */
    public function isEndurance(): bool
    {
        return in_array($this, [self::Running, self::Cycling, self::Swimming], true);
    }

    /**
     * Palavras do nome do exercício que ganham prioridade neste esporte e quanto (somado ao "básico do grupo", que vale até 27).
     *
     * @return list<array{0: string, 1: int}>
     */
    public function emphasis(): array
    {
        return match ($this) {
            self::Running => [
                ['afundo', 10], ['bulgaro', 10], ['step', 10], ['unilateral', 8], ['uma perna', 8], ['single leg', 8],
                ['terra romeno', 10], ['stiff', 8], ['hip thrust', 8], ['elevacao pelvica', 8], ['ponte de gluteo', 6],
                ['panturrilha', 10], ['prancha', 8], ['pallof', 10], ['dead bug', 8], ['abducao', 6], ['caminhada lateral', 8], ['monster', 8], ['abdutora', 6],
            ],
            self::Cycling => [
                ['leg press', 8], ['agachamento', 8], ['terra', 8], ['hip thrust', 8], ['panturrilha', 6], ['prancha', 8], ['dead bug', 6], ['pallof', 6],
                ['remada', 8], ['puxada', 6], ['hiperextensao', 6],
            ],
            self::Swimming => [
                ['puxada', 10], ['barra fixa', 10], ['pulldown', 10], ['pullover', 10], ['remada', 8], ['rotacao externa', 10], ['face pull', 10],
                ['crucifixo invertido', 10], ['peck deck invertido', 10], ['prancha', 8], ['dead bug', 6], ['abdominal', 4], ['triceps', 4], ['agachamento', 4],
            ],
            self::Racket => [
                ['woodchop', 10], ['pallof', 10], ['russian', 10], ['torcao', 8], ['rotacao externa', 8], ['face pull', 8], ['crucifixo invertido', 8], ['elevacao lateral', 6],
                ['flexao de punho', 10], ['extensao de punho', 10], ['pronacao', 10], ['rosca inversa', 8], ['afundo', 8], ['step', 6], ['agachamento', 6], ['panturrilha', 4], ['remada', 6],
            ],
            self::Soccer => [
                ['agachamento', 8], ['afundo', 8], ['bulgaro', 8], ['step', 6], ['terra romeno', 10], ['stiff', 8], ['elevacao pelvica', 10], ['nordic', 10], ['hip thrust', 8],
                ['adutor', 10], ['abdutora', 6], ['panturrilha', 6], ['prancha', 6], ['dead bug', 4], ['leg press', 4],
            ],
            self::Court => [
                ['agachamento', 8], ['afundo', 8], ['step', 6], ['leg press', 6], ['panturrilha', 10], ['elevacao pelvica', 6], ['hip thrust', 6],
                ['rotacao externa', 8], ['face pull', 8], ['elevacao lateral', 6], ['prancha', 8], ['dead bug', 6], ['remada', 6], ['puxada', 6],
            ],
            self::MartialArts => [
                ['remada', 8], ['barra fixa', 8], ['puxada', 8], ['pulldown', 6], ['woodchop', 8], ['russian', 8], ['prancha', 8], ['abdominal', 6], ['flexao', 6], ['paralelas', 6],
                ['agachamento', 6], ['afundo', 6], ['terra', 6], ['rosca inversa', 8], ['farmer', 10], ['flexao de punho', 8],
            ],
        };
    }

    /**
     * Espaços extras de cada treino (grupo, tipo), que entram no lugar dos últimos do dia: o que o esporte pede e o treino comum
     * deixaria de fora. Rodam entre os treinos da semana.
     *
     * @return list<array{0: string, 1: string}>
     */
    public function extraSlots(): array
    {
        return match ($this) {
            self::Running => [['legs', 'calf'], ['glutes', 'any'], ['core', 'any']],
            self::Cycling => [['core', 'any'], ['legs', 'calf'], ['back', 'compound']],
            self::Swimming => [['shoulders', 'isolation'], ['core', 'any'], ['back', 'compound']],
            self::Racket => [['core', 'any'], ['shoulders', 'isolation'], ['forearms', 'any']],
            self::Soccer => [['legs', 'isolation'], ['glutes', 'any'], ['core', 'any']],
            self::Court => [['legs', 'calf'], ['shoulders', 'isolation'], ['core', 'any']],
            self::MartialArts => [['core', 'any'], ['back', 'compound'], ['forearms', 'any']],
        };
    }

    /**
     * Exercícios que o treino comum nunca sorteia (reabilitação ou muito específicos) mas que fazem sentido neste esporte.
     * Palavras do nome sem acento.
     *
     * @return list<string>
     */
    public function unlocks(): array
    {
        return match ($this) {
            self::Running, self::Cycling => [],
            self::Swimming, self::Court => ['rotacao externa', 'rotacao interna'],
            self::Racket => ['rotacao externa', 'rotacao interna', 'pronacao', 'flexao de punho', 'extensao de punho', 'rosca inversa'],
            self::Soccer => ['treno', 'sled'],
            self::MartialArts => ['rosca inversa', 'flexao de punho', 'extensao de punho', 'farmer', 'battle rope'],
        };
    }

    /**
     * Cuidados que não dependem do treino de academia: aparecem como orientação da fase.
     *
     * @return list<string>
     */
    public function tips(): array
    {
        return match ($this) {
            self::Running => ['Evite um treino pesado de pernas no dia anterior a um treino longo ou de intensidade na corrida.', 'Priorize a recuperação: a força só ajuda a corrida se você chegar descansado aos treinos de corrida.'],
            self::Cycling => ['Evite um treino pesado de pernas no dia anterior a um pedal longo ou intenso.', 'Alongue e fortaleça os glúteos e o core: quem pedala muito encurta o quadril e perde estabilidade.'],
            self::Swimming => ['Mantenha o trabalho de ombro leve e com boa técnica: o ombro já é muito exigido na água.', 'Costas e ombros fortes e equilibrados protegem o ombro de quem nada.'],
            self::Racket => ['Treine os dois lados do corpo: o esporte sobrecarrega o lado dominante.', 'Cuide do ombro e do antebraço com carga leve e muitas repetições.'],
            self::Soccer => ['Posteriores de coxa e adutores fortes reduzem o risco de lesão em arrancadas e mudanças de direção.', 'Evite um treino pesado de pernas na véspera de jogo.'],
            self::Court => ['Aterrisse dos saltos com os joelhos alinhados aos pés e flexionados, e fortaleça glúteos e panturrilha.', 'Evite um treino pesado de pernas na véspera de jogo.'],
            self::MartialArts => ['Fortaleça as costas e a pegada na mesma medida em que treina os golpes.', 'Evite um treino pesado de pernas na véspera de treino de luta intenso.'],
        };
    }
}
