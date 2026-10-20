<?php

namespace App\Support;

use App\Enums\GymEquipmentAccess;
use App\Enums\GymLevel;
use App\Enums\GymRestriction;

/**
 * Lê um exercício do catálogo (nome, grupo e equipamento) e diz o que o treino automático precisa saber: se é composto ou
 * isolado, que equipamento exige, para qual nível serve e que região do corpo mais exige. É inferência por palavras-chave,
 * de propósito: o catálogo não traz essas marcas e o treino sempre passa pela revisão da pessoa antes de ser salvo.
 */
final class GymExerciseClassifier
{
    public const COMPOUND = 'compound';

    public const ISOLATION = 'isolation';

    /** Só para quem já tem prática: técnica exigente ou risco maior. */
    private const ADVANCED_ONLY = ['levantamento terra', 'good morning', 'agachamento frontal', 'agachamento bulgaro', 'turkish', 'clean', 'sissy', 'rosca 21', 'ab wheel', 'barra fixa', 'stiff', 'terra romeno'];

    /** Nem o intermediário: só para avançado. */
    private const EXPERT_ONLY = ['turkish', 'clean', 'sissy', 'good morning', 'levantamento terra sumo'];

    /** Reabilitação ou muito específico: fora das escolhas automáticas. */
    private const NEVER_AUTO = ['rotacao externa', 'rotacao interna', 'pronacao', 'flexao de punho', 'extensao de punho', 'rosca inversa', 'farmer', 'arremesso', 'battle rope', 'trenó', 'treno', 'sled', 'kettlebell swing', 'turkish', 'clean'];

    /**
     * Quanto o exercício é um "básico" do seu grupo (10 = o primeiro a escolher, 1 = só se não houver outro). Os não listados
     * valem 5. A primeira palavra que bater vale, então os mais específicos vêm antes.
     *
     * @var list<array{0: string, 1: int}>
     */
    private const PRIORITY = [
        ['supino reto', 9], ['supino inclinado', 8], ['supino na maquina', 8], ['flexao com pes', 5], ['flexao de braco', 6], ['paralelas', 5], ['supino fechado', 3], ['supino declinado', 2],
        ['crucifixo reto', 8], ['crossover', 8], ['peck deck invertido', 8], ['peck deck', 8], ['crucifixo inclinado', 7], ['pullover', 4], ['crucifixo invertido', 8],
        ['puxada atras', 2], ['puxada frontal pegada neutra', 8], ['puxada frontal', 9], ['remada baixa', 9], ['remada maquina', 9], ['remada curvada', 8], ['remada unilateral', 8], ['remada cavalinho', 6], ['remada', 7],
        ['barra fixa supinada', 4], ['barra fixa', 6], ['face pull', 8], ['pulldown', 8], ['encolhimento', 5], ['hiperextensao', 4],
        ['desenvolvimento com halteres', 9], ['desenvolvimento na maquina', 9], ['desenvolvimento militar', 8], ['arnold', 6],
        ['elevacao lateral no cabo', 8], ['elevacao lateral', 9], ['elevacao frontal', 4],
        ['rosca direta', 9], ['rosca alternada', 9], ['rosca martelo', 9], ['rosca simultanea', 8], ['rosca no cabo', 8], ['rosca scott', 7], ['rosca concentrada', 6], ['rosca 21', 2],
        ['triceps corda', 9], ['triceps barra', 9], ['triceps testa', 8], ['triceps frances', 7], ['mergulho no banco', 6], ['kickback', 5],
        ['agachamento livre', 9], ['leg press', 9], ['hack', 8], ['agachamento sumo', 7], ['afundo estatico', 7], ['afundo reverso', 7], ['afundo', 6], ['step-up', 6],
        ['terra romeno', 8], ['stiff', 7], ['levantamento terra sumo', 5], ['levantamento terra', 8], ['agachamento frontal', 5], ['agachamento bulgaro', 5], ['sissy', 1], ['good morning', 2],
        ['cadeira extensora', 9], ['mesa flexora', 9], ['cadeira flexora unilateral', 6], ['cadeira flexora', 9], ['elevacao pelvica com pernas', 3],
        ['panturrilha em pe', 9], ['panturrilha sentada', 9], ['panturrilha no leg press', 8], ['panturrilha unilateral', 5],
        ['hip thrust', 9], ['maquina de gluteo', 8], ['ponte de gluteo', 7], ['elevacao pelvica unilateral', 6], ['coice no cabo', 7], ['cadeira abdutora', 6], ['caminhada lateral', 4], ['abducao', 4],
        ['prancha frontal', 9], ['abdominal supra', 8], ['abdominal na maquina', 8], ['dead bug', 8], ['elevacao de pernas deitado', 7], ['abdominal no cabo', 7], ['prancha lateral', 7], ['pallof', 7],
        ['abdominal infra', 6], ['abdominal obliquo', 6], ['russian twist', 5], ['woodchop', 5], ['elevacao de pernas na paralela', 4], ['ab wheel', 3],
    ];

    /** @var array<string, list<string>> palavras (sem acento) dos exercícios que mais exigem cada região */
    private const RESTRICTED = [
        'knee' => ['agachamento', 'leg press', 'hack', 'afundo', 'passada', 'step-up', 'step up', 'extensora', 'sissy', 'salto', 'burpee', 'pular corda', 'goblet', 'lunge'],
        'shoulder' => ['desenvolvimento', 'militar', 'arnold', 'remada alta', 'mergulho', 'paralelas', 'elevacao frontal', 'elevacao lateral', 'supino inclinado', 'face pull', 'puxada atras', 'dips'],
        'lower_back' => ['levantamento terra', 'terra', 'stiff', 'good morning', 'remada curvada', 'hiperextensao', 'bom dia', 'agachamento livre', 'agachamento frontal', 'cavalinho', 't-bar', 'kettlebell swing'],
    ];

    /** Nome sem acento, em minúscula: a base de toda comparação. */
    public static function normalize(string $texto): string
    {
        return strtr(mb_strtolower(trim($texto)), [
            'á' => 'a', 'à' => 'a', 'ã' => 'a', 'â' => 'a', 'é' => 'e', 'ê' => 'e', 'í' => 'i',
            'ó' => 'o', 'ô' => 'o', 'õ' => 'o', 'ú' => 'u', 'ü' => 'u', 'ç' => 'c',
        ]);
    }

    /** Composto (vários músculos e articulações) ou isolado. Panturrilha conta como isolado, para o treino de pernas. */
    public static function kind(string $nome, string $grupo): string
    {
        $n = self::normalize($nome);

        $compostos = match ($grupo) {
            'chest' => ['supino', 'flexao', 'paralelas', 'dips'],
            'back' => ['barra fixa', 'puxada', 'remada', 'terra'],
            'shoulders' => ['desenvolvimento', 'arnold'],
            'legs' => ['agachamento', 'leg press', 'hack', 'afundo', 'step-up', 'terra', 'stiff', 'good morning', 'goblet'],
            'glutes' => ['hip thrust', 'step-up', 'elevacao pelvica'],
            default => [],
        };

        foreach ($compostos as $palavra) {
            if (str_contains($n, $palavra)) {
                return self::COMPOUND;
            }
        }

        return self::ISOLATION;
    }

    /** Quanto é um "básico" do grupo (ver PRIORITY): de 1 a 10. */
    public static function priority(string $nome): int
    {
        $n = self::normalize($nome);

        foreach (self::PRIORITY as [$palavra, $valor]) {
            if (str_contains($n, $palavra)) {
                return $valor;
            }
        }

        return 5;
    }

    /**
     * A "família" do movimento: dois supinos ou dois afundos no mesmo treino é repetir o estímulo. Só serve para dar
     * preferência a famílias diferentes dentro do treino, nunca para barrar.
     */
    public static function family(string $nome): string
    {
        $palavras = preg_split('/[\s(\/]+/', self::normalize($nome), -1, PREG_SPLIT_NO_EMPTY) ?: [''];

        return in_array($palavras[0], ['elevacao', 'cadeira', 'mesa', 'peck', 'barra', 'ponte', 'hip'], true) && isset($palavras[1])
            ? $palavras[0].' '.$palavras[1]
            : $palavras[0];
    }

    /** Condicionamento (saltos, corda, burpee): entra no bloco de cardio, não nos exercícios de força. */
    public static function isConditioning(string $nome): bool
    {
        $n = self::normalize($nome);

        foreach (['salto', 'pular corda', 'jump rope', 'burpee'] as $palavra) {
            if (str_contains($n, $palavra)) {
                return true;
            }
        }

        return false;
    }

    public static function isCalf(string $nome): bool
    {
        return str_contains(self::normalize($nome), 'panturrilha');
    }

    /** Bíceps ou tríceps, para dividir o grupo "braços" entre os treinos de empurrar e puxar. */
    public static function armPart(string $nome): ?string
    {
        $n = self::normalize($nome);

        foreach (['triceps', 'mergulho no banco', 'kickback'] as $palavra) {
            if (str_contains($n, $palavra)) {
                return 'triceps';
            }
        }

        foreach (['rosca', 'martelo', 'scott'] as $palavra) {
            if (str_contains($n, $palavra)) {
                return 'biceps';
            }
        }

        return null;
    }

    /**
     * Equipamentos que o exercício pede, lendo a dica do catálogo e o nome.
     *
     * @return list<string> machine, cable, barbell, dumbbell, bodyweight ou special
     */
    public static function equipment(?string $dica, string $nome = ''): array
    {
        $t = self::normalize(($dica ?? '').' '.$nome);
        $classes = [];

        if (preg_match('/maquina|leg press|smith|hack|esteira|bike|eliptico|escada|remo |peck|voador/', $t)) {
            $classes[] = 'machine';
        }

        if (preg_match('/cabo|polia|crossover|pulley|puxada/', $t)) {
            $classes[] = 'cable';
        }

        if (preg_match('/barra|anilha/', $t) && ! str_contains($t, 'barra fixa')) {
            $classes[] = 'barbell';
        }

        if (preg_match('/halter|kettlebell/', $t)) {
            $classes[] = 'dumbbell';
        }

        if (str_contains($t, 'peso do corpo') || str_contains($t, 'barra fixa')) {
            $classes[] = 'bodyweight';
        }

        if (preg_match('/trx|corda|roda|mini band|medicine|caixa|elastico|tornozeleira/', $t)) {
            $classes[] = 'special';
        }

        return array_values(array_unique($classes === [] ? ['special'] : $classes));
    }

    /** O exercício cabe no que a pessoa tem? */
    public static function fitsAccess(string $nome, ?string $dica, GymEquipmentAccess $acesso): bool
    {
        $n = self::normalize($nome);
        $classes = self::equipment($dica, $nome);

        if ($acesso === GymEquipmentAccess::FullGym) {
            return true;
        }

        // Precisam de barra fixa, paralela, banco romano ou aparelho: não existem em casa.
        foreach (['barra fixa', 'paralela', 'hiperextensao', 'banco romano', 'scott', 'peck', 'esteira', 'bicicleta', 'eliptico', 'escada', 'remo ergometrico', 'hiit', 'circuito metabolico'] as $palavra) {
            if (str_contains($n, $palavra)) {
                return false;
            }
        }

        if ($acesso === GymEquipmentAccess::Home) {
            $precisaDeAparelho = array_intersect($classes, ['machine', 'cable']) !== [] && array_intersect($classes, ['dumbbell', 'bodyweight']) === [];
            $soBarra = in_array('barbell', $classes, true) && array_intersect($classes, ['dumbbell', 'bodyweight']) === [];

            return ! $precisaDeAparelho && ! $soBarra && ! (in_array('special', $classes, true) && count($classes) === 1 && ! str_contains($n, 'pular corda') && ! str_contains($n, 'burpee') && ! str_contains($n, 'salto'));
        }

        // Só o peso do corpo: o que é do corpo, o condicionamento e os movimentos que se fazem sem carga.
        return in_array('bodyweight', $classes, true) || self::isConditioning($nome) || (bool) preg_match('/afundo|agachamento sumo|step-up|ponte/', $n);
    }

    /** O exercício serve ao nível da pessoa? (iniciante não pega técnica exigente) */
    public static function fitsLevel(string $nome, GymLevel $nivel): bool
    {
        $n = self::normalize($nome);

        $proibidas = match ($nivel) {
            GymLevel::Beginner => self::ADVANCED_ONLY,
            GymLevel::Intermediate => self::EXPERT_ONLY,
            GymLevel::Advanced => [],
        };

        foreach ($proibidas as $palavra) {
            if (str_contains($n, $palavra)) {
                return false;
            }
        }

        return true;
    }

    /** Exercício de reabilitação ou muito específico: nunca entra sozinho. */
    public static function isNeverAuto(string $nome): bool
    {
        $n = self::normalize($nome);

        foreach (self::NEVER_AUTO as $palavra) {
            if (str_contains($n, self::normalize($palavra))) {
                return true;
            }
        }

        return false;
    }

    /**
     * O exercício pesa numa região que a pessoa quer proteger?
     *
     * @param  list<GymRestriction>  $restricoes
     */
    public static function hitsRestriction(string $nome, array $restricoes): bool
    {
        $n = self::normalize($nome);

        foreach ($restricoes as $restricao) {
            foreach (self::RESTRICTED[$restricao->value] as $palavra) {
                if (str_contains($n, $palavra)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Quanto o nível prefere o equipamento: o iniciante, máquina e cabo (guiados); o avançado, barra e halteres (livres).
     */
    public static function preference(?string $dica, string $nome, GymLevel $nivel): int
    {
        $classes = self::equipment($dica, $nome);
        $peso = match ($nivel) {
            GymLevel::Beginner => ['machine' => 4, 'cable' => 3, 'dumbbell' => 2, 'bodyweight' => 2, 'barbell' => 1, 'special' => 0],
            GymLevel::Intermediate => ['dumbbell' => 3, 'machine' => 3, 'cable' => 3, 'barbell' => 3, 'bodyweight' => 2, 'special' => 0],
            GymLevel::Advanced => ['barbell' => 4, 'dumbbell' => 3, 'bodyweight' => 3, 'cable' => 2, 'machine' => 2, 'special' => 0],
        };

        return max(array_map(fn (string $c) => $peso[$c] ?? 0, $classes));
    }
}
