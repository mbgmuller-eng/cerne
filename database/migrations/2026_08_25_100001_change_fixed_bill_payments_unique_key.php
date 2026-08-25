<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * (fixed_bill_id, year, month) só permitia UM vencimento por mês — certo
     * pra mensal/anual, quebrado pra semanal (4-5 vencimentos no mesmo mês).
     * A chave de idempotência vira (fixed_bill_id, due_date): cada data
     * concreta é única por conta, não importa a periodicidade. year/month
     * continuam existindo (dashboards agregam por competência), só deixam
     * de ser a trava.
     */
    public function up(): void
    {
        // Ordem importa: fixed_bill_id sustenta a foreign key da tabela.
        // Derrubar o único índice que cobre essa coluna antes de criar o
        // substituto quebra com "Cannot drop index... needed in a foreign
        // key constraint" (MySQL 1553) — por isso cria o novo primeiro.
        Schema::table('fixed_bill_payments', function (Blueprint $table) {
            $table->unique(['fixed_bill_id', 'due_date']);
        });

        Schema::table('fixed_bill_payments', function (Blueprint $table) {
            $table->dropUnique(['fixed_bill_id', 'year', 'month']);
        });
    }

    public function down(): void
    {
        Schema::table('fixed_bill_payments', function (Blueprint $table) {
            $table->unique(['fixed_bill_id', 'year', 'month']);
        });

        Schema::table('fixed_bill_payments', function (Blueprint $table) {
            $table->dropUnique(['fixed_bill_id', 'due_date']);
        });
    }
};
