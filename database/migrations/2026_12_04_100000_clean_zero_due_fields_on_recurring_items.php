<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * O formulário de conta fixa e de receita recorrente gravava 0 em dia da semana e mês de quem não os usa (campo vazio virava `(int) ''`).
 * O 0 voltava na edição e fazia a validação recusar um campo escondido, e o "Salvar" parecia morto. Limpa o que ficou: mês só vale em conta anual,
 * dia da semana só em conta semanal. O que a recorrência não usa nunca é lido (FixedBill/RecurringIncome::occurrencesInMonth), então é só dado sujo.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['fixed_bills', 'recurring_incomes'] as $tabela) {
            DB::table($tabela)->where('due_month', 0)->orWhere(fn ($q) => $q->where('recurrence', '!=', 'annual')->whereNotNull('due_month'))->update(['due_month' => null]);
            DB::table($tabela)->where('recurrence', '!=', 'weekly')->whereNotNull('due_weekday')->update(['due_weekday' => null]);
            DB::table($tabela)->where('due_day', 0)->update(['due_day' => null]);
        }
    }

    public function down(): void
    {
        // Dado sujo que não se restaura.
    }
};
