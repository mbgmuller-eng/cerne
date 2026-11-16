<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A despesa que quitou o vencimento de uma conta fixa. Duas razões:
 * - "Conciliar": a conta paga pelo extrato (o lançamento já existe) vira paga LIGADA a ele, sem criar despesa nova nem mexer no saldo; o índice único
 *   impede que o mesmo lançamento baixe duas contas (regra 4: a proteção é do banco, não de um "se já existe");
 * - "Pagar" pelo Cerne, que gera a despesa, passa a guardar a ligação, e essa despesa nunca é oferecida de novo como candidata a conciliar.
 *
 * Nula para o que já estava pago antes (o histórico não é reescrito). Apagar a despesa solta a ligação e a conta continua paga.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fixed_bill_payments', function (Blueprint $table) {
            $table->foreignUuid('expense_id')->nullable()->after('paid_at')->constrained('expense_records')->nullOnDelete();
            $table->unique('expense_id', 'fixed_bill_payments_expense_unique');
        });
    }

    public function down(): void
    {
        Schema::table('fixed_bill_payments', function (Blueprint $table) {
            $table->dropUnique('fixed_bill_payments_expense_unique');
            $table->dropConstrainedForeignId('expense_id');
        });
    }
};
