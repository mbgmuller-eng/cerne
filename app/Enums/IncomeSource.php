<?php

namespace App\Enums;

use App\Enums\Concerns\HasOptions;

/** De onde vem o dinheiro que o profissional recebe. Só o honorário fixo nasce sozinho nesta fase; as demais origens ganham regras próprias nas próximas. */
enum IncomeSource: string
{
    use HasOptions;

    case FixedFee = 'fixed_fee';
    case InsuranceCommission = 'insurance_commission';
    case InsuranceAgency = 'insurance_agency';
    case AssetFee = 'asset_fee';
    case ConsortiumCommission = 'consortium_commission';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::FixedFee => 'Honorário fixo',
            self::InsuranceCommission => 'Comissão de seguro',
            self::InsuranceAgency => 'Agenciamento',
            self::AssetFee => 'Percentual sobre patrimônio',
            self::ConsortiumCommission => 'Comissão de consórcio',
            self::Other => 'Outros',
        };
    }

    /** Classes do selo na lista (claro e escuro). */
    public function badgeClasses(): string
    {
        return match ($this) {
            self::FixedFee => 'bg-sky-100 text-sky-800 dark:bg-sky-500/15 dark:text-sky-300',
            self::InsuranceCommission => 'bg-emerald-100 text-emerald-800 dark:bg-emerald-500/15 dark:text-emerald-300',
            self::InsuranceAgency => 'bg-teal-100 text-teal-800 dark:bg-teal-500/15 dark:text-teal-300',
            self::AssetFee => 'bg-violet-100 text-violet-800 dark:bg-violet-500/15 dark:text-violet-300',
            self::ConsortiumCommission => 'bg-amber-100 text-amber-800 dark:bg-amber-500/15 dark:text-amber-300',
            self::Other => 'bg-slate-100 text-slate-700 dark:bg-white/10 dark:text-slate-300',
        };
    }
}
