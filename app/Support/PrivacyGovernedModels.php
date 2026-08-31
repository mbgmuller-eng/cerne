<?php

namespace App\Support;

use App\Models\BankAccount;
use App\Models\CreditCard;
use App\Models\ExpenseRecord;
use App\Models\FixedBill;
use App\Models\Goal;
use App\Models\IncomeRecord;
use App\Models\InsurancePolicy;
use App\Models\InvestmentRecord;
use App\Models\RecurringIncome;
use App\Models\Scopes\MemberPrivacyScope;
use App\Models\Scopes\ProfileScope;

/**
 * Os lançamentos que carregam privacidade por registro (oculto do
 * cônjuge) — usado onde é preciso perguntar "existe ALGO oculto neste
 * perfil?" sem se prender a um domínio específico (ver HasPrivacyTabs,
 * ConsultantPortfolioService::resumoDeExibicao()).
 *
 * Conta e cartão usam `visible_to_partner` (campo próprio deles, anterior
 * a `is_private` — ver HasSharingFlags), sentido invertido dos demais;
 * os outros usam `is_private`.
 *
 * Não inclui InvestmentTransaction/InvestmentPerformance (herdam a
 * privacidade do InvestmentRecord) nem FinancialReserve (deriva
 * automaticamente do InvestorProfile — ver ReservePrivacyScope).
 */
class PrivacyGovernedModels
{
    public const ALL = [
        ExpenseRecord::class,
        IncomeRecord::class,
        RecurringIncome::class,
        FixedBill::class,
        InvestmentRecord::class,
        BankAccount::class,
        CreditCard::class,
        InsurancePolicy::class,
        Goal::class,
    ];

    /**
     * @param  array<int, class-string>  $models
     */
    public static function anyPrivate(string $profileId, array $models = self::ALL): bool
    {
        foreach ($models as $modelClass) {
            $query = $modelClass::query()
                ->withoutGlobalScope(ProfileScope::class)
                ->withoutGlobalScope(MemberPrivacyScope::class)
                ->where('profile_id', $profileId);

            $temOculto = $modelClass::hasVisibleToPartnerFlag()
                ? $query->where('visible_to_partner', false)->exists()
                : $query->where('is_private', true)->exists();

            if ($temOculto) {
                return true;
            }
        }

        return false;
    }
}
