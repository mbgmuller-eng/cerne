<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Privacidade deixa de ser "uma configuração por casal" (profile_access_settings)
 * e passa a ser "um campo por lançamento" — cada despesa, receita, conta
 * fixa, investimento, conta bancária, cartão, apólice ou objetivo decide
 * por si só se fica oculto do cônjuge, não mais uma flag valendo pra tudo
 * de uma vez. Ver MemberPrivacyScope.
 */
return new class extends Migration
{
    private const TABELAS = [
        'expense_records',
        'income_records',
        'recurring_incomes',
        'fixed_bills',
        'investment_records',
        'bank_accounts',
        'credit_cards',
        'insurance_policies',
        'goals',
    ];

    public function up(): void
    {
        foreach (self::TABELAS as $tabela) {
            Schema::table($tabela, function (Blueprint $table): void {
                $table->boolean('is_private')->default(false)->after('member_id');
            });
        }
    }

    public function down(): void
    {
        foreach (self::TABELAS as $tabela) {
            Schema::table($tabela, function (Blueprint $table): void {
                $table->dropColumn('is_private');
            });
        }
    }
};
