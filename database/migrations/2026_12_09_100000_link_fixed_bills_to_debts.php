<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Conta fixa nascida de uma dívida (financiamento, empréstimo, consórcio, acordo):
 * - `fixed_bills.debt_id` (único): uma dívida tem no máximo UMA conta fixa. O índice único é a proteção contra a corrida entre o cadastro, a
 *   rotina diária e outra aba (regra 4), não um "se já existe";
 * - `fixed_bills.auto_ended_at`: o Cerne encerrou a conta porque a dívida acabou. Quem a desativa à mão não preenche isto, e a rotina nunca
 *   a reativa; já a que o Cerne encerrou volta sozinha se a dívida voltar a ter parcela em aberto (pagamento desfeito);
 * - `fixed_bill_payments.debt_installment_id` (único): cada vencimento espelha UMA parcela, então o pagamento acontece uma vez só (pela parcela);
 * - `fixed_bill_payments.expected_amount`: o valor previsto daquele vencimento (a parcela real, que muda com SAC, reajuste e contemplação),
 *   no lugar do valor único da conta.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fixed_bills', function (Blueprint $table) {
            $table->foreignUuid('debt_id')->nullable()->after('profile_id')->constrained('debts')->nullOnDelete();
            $table->unique('debt_id', 'fixed_bills_debt_unique');
            $table->timestamp('auto_ended_at')->nullable()->after('is_active');
        });

        Schema::table('fixed_bill_payments', function (Blueprint $table) {
            $table->foreignUuid('debt_installment_id')->nullable()->after('fixed_bill_id')->constrained('debt_installments')->nullOnDelete();
            $table->unique('debt_installment_id', 'fixed_bill_payments_installment_unique');
            $table->decimal('expected_amount', 15, 2)->nullable()->after('amount_paid');
        });
    }

    public function down(): void
    {
        Schema::table('fixed_bill_payments', function (Blueprint $table) {
            $table->dropUnique('fixed_bill_payments_installment_unique');
            $table->dropConstrainedForeignId('debt_installment_id');
            $table->dropColumn('expected_amount');
        });

        Schema::table('fixed_bills', function (Blueprint $table) {
            $table->dropUnique('fixed_bills_debt_unique');
            $table->dropConstrainedForeignId('debt_id');
            $table->dropColumn('auto_ended_at');
        });
    }
};
