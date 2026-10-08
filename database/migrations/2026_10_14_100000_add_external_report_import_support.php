<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Importar um relatório de outro app traz lançamentos que muitas vezes já estão refletidos no saldo
        // atual da conta (um mês de agosto importado em outubro). A pessoa escolhe, no envio, se o saldo deve
        // mudar; o lançamento guarda a decisão para que editar ou apagar depois não mexa no saldo à toa.
        Schema::table('document_uploads', function (Blueprint $table) {
            $table->boolean('applies_to_balance')->default(true)->after('credit_card_id');
            // Leitura descartada continua contando no limite diário (cada leitura é uma chamada paga à API).
            $table->timestamp('dismissed_at')->nullable()->after('committed_at');
        });

        Schema::table('expense_records', function (Blueprint $table) {
            $table->boolean('affects_balance')->default(true)->after('bank_account_id');
        });

        Schema::table('income_records', function (Blueprint $table) {
            $table->boolean('affects_balance')->default(true)->after('bank_account_id');
        });
    }

    public function down(): void
    {
        Schema::table('income_records', fn (Blueprint $table) => $table->dropColumn('affects_balance'));
        Schema::table('expense_records', fn (Blueprint $table) => $table->dropColumn('affects_balance'));
        Schema::table('document_uploads', fn (Blueprint $table) => $table->dropColumn(['applies_to_balance', 'dismissed_at']));
    }
};
