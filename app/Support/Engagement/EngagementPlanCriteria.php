<?php

namespace App\Support\Engagement;

use App\Enums\GoalStatus;
use App\Enums\InsuranceType;
use App\Enums\ReserveType;
use App\Models\BankAccount;
use App\Models\ExpenseRecord;
use App\Models\FinancialProfile;
use App\Models\FinancialReserve;
use App\Models\FixedBill;
use App\Models\Goal;
use App\Models\IncomeRecord;
use App\Models\InsurancePolicy;
use App\Models\InvestmentRecord;
use App\Models\InvestorProfile;
use App\Support\ProfileContext;

/**
 * Critérios automáticos dos itens do passo a passo: o que o app já sabe do cliente e basta para dar o item como feito.
 *
 * A conferência é feita sempre da perspectiva do consultor (que enxerga tudo do cliente), para o resultado não mudar conforme
 * quem abre a tela. O que sai daqui é só "feito ou não" por critério, nunca um valor.
 */
final class EngagementPlanCriteria
{
    /** Despesas lançadas nos últimos 30 dias para o orçamento contar como em uso. */
    public const MIN_RECENT_EXPENSES = 5;

    /** @return array<string, string> chave => rótulo */
    public static function options(): array
    {
        return [
            'accounts' => 'Contas bancárias cadastradas',
            'income_recent' => 'Receitas lançadas nos últimos 30 dias',
            'expenses_recent' => 'Despesas lançadas nos últimos 30 dias ('.self::MIN_RECENT_EXPENSES.' ou mais)',
            'fixed_bills' => 'Contas fixas cadastradas',
            'reserve_peace' => 'Reserva de paz completa',
            'policies' => 'Apólices de seguro cadastradas',
            'life_insurance' => 'Seguro de vida cadastrado',
            'investor_profile' => 'Perfil de investidor preenchido',
            'investments' => 'Investimentos cadastrados',
            'goals' => 'Objetivos cadastrados',
        ];
    }

    public static function exists(string $chave): bool
    {
        return array_key_exists($chave, self::options());
    }

    /**
     * @param  list<string>  $chaves
     * @return array<string, bool> chave => atendido
     */
    public static function evaluate(FinancialProfile $perfil, array $chaves): array
    {
        $contexto = app(ProfileContext::class);
        $antes = [$contexto->profile(), $contexto->member(), $contexto->isConsultant()];

        // Os models de dado só enxergam o perfil do contexto. Entra no perfil do cliente como consultor e restaura no fim.
        $contexto->set($perfil, null, true);

        try {
            $resultado = [];

            foreach (array_unique($chaves) as $chave) {
                $resultado[$chave] = self::check($chave);
            }

            return $resultado;
        } finally {
            $antes[0] !== null ? $contexto->set($antes[0], $antes[1], $antes[2]) : $contexto->clear();
        }
    }

    private static function check(string $chave): bool
    {
        return match ($chave) {
            'accounts' => BankAccount::query()->where('is_active', true)->exists(),
            'income_recent' => IncomeRecord::query()->whereDate('received_date', '>=', now()->subDays(30)->toDateString())->exists(),
            'expenses_recent' => ExpenseRecord::query()->whereDate('expense_date', '>=', now()->subDays(30)->toDateString())->count() >= self::MIN_RECENT_EXPENSES,
            'fixed_bills' => FixedBill::query()->where('is_active', true)->exists(),
            'reserve_peace' => self::reservePeaceComplete(),
            'policies' => InsurancePolicy::query()->where('is_active', true)->exists(),
            'life_insurance' => InsurancePolicy::query()->where('is_active', true)->where('insurance_type', InsuranceType::Vida->value)->exists(),
            'investor_profile' => InvestorProfile::query()->exists(),
            'investments' => InvestmentRecord::query()->where('is_active', true)->exists(),
            'goals' => Goal::query()->where('status', GoalStatus::Active->value)->exists(),
            default => false,
        };
    }

    private static function reservePeaceComplete(): bool
    {
        $reservas = FinancialReserve::query()->where('reserve_type', ReserveType::Paz->value)->get();

        return $reservas->isNotEmpty() && $reservas->every(fn (FinancialReserve $r) => $r->isComplete());
    }
}
