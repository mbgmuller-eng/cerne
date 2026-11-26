<?php

namespace App\Support\Activities;

/**
 * As perguntas de prontidão antes de um plano de atividades. Redação do Cerne, inspirada nos questionários de triagem usados em academias, e
 * ainda pendente de revisão por um profissional (entra em App\Support\Rules\RuleBook). Qualquer "sim" recomenda liberação médica.
 */
final class ReadinessQuestions
{
    public const VALID_MONTHS = 12;

    /** @var array<string, string> */
    public const ALL = [
        'heart' => 'Algum médico já disse que você tem problema no coração ou pressão alta?',
        'chest_pain' => 'Você sente dor no peito em repouso, no dia a dia ou durante o exercício?',
        'dizziness' => 'Nos últimos 12 meses, você desmaiou ou perdeu o equilíbrio por tontura?',
        'breath' => 'Você sente falta de ar fora do comum em esforços leves?',
        'medication' => 'Você toma remédio para pressão ou para o coração?',
        'joints' => 'Há problema em ossos ou articulações que possa piorar com exercício?',
        'supervision' => 'Algum médico já disse que você só deve se exercitar com acompanhamento?',
    ];
}
