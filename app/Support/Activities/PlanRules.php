<?php

namespace App\Support\Activities;

/**
 * Os números do plano da semana, em um lugar só. São escolhas de produto, pendentes de revisão por um profissional de educação física
 * (entram em App\Support\Rules\RuleBook). A referência de 150 a 300 minutos por semana é a da OMS para adultos.
 */
final class PlanRules
{
    public const WHO_MINIMUM = 150;

    public const CEILING = 300;

    /** No objetivo de perder peso, o plano mira o que a pessoa já faz mais isto (entre o mínimo e o teto). */
    public const WEIGHT_LOSS_EXTRA = 90;

    public const MAX_WEEKLY_INCREASE = 15;

    public const DELOAD_EVERY = 4;

    public const DELOAD_PERCENT = 20;

    /** A progressão só anda quando a semana teve ao menos isto do aeróbico planejado. */
    public const ADHERENCE_PERCENT = 80;

    /** Sem liberação médica e sem nada no diário, o plano leve começa com isto por semana. */
    public const LIGHT_MINUTES = 40;

    public const LIGHT_EFFORT = 4;

    public const AEROBIC_EFFORT = 5;

    public const STRENGTH_DAYS_MIN = 2;

    public const STRENGTH_DAYS_MAX = 3;

    public const FINISHER_MINUTES = 5;

    public const SESSION_MAX = 60;

    public const WEEKS_SHOWN = 12;
}
