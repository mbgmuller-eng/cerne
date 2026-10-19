<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('debts', function (Blueprint $table) {
            // A declaração do imposto de renda pede o CPF ou CNPJ do credor e a situação da dívida em 31/12.
            $table->string('creditor_document', 20)->nullable()->after('creditor');
            // Sem ela, a data do contrato é estimada pelo primeiro vencimento.
            $table->date('contracted_on')->nullable()->after('first_due_date');
        });
    }

    public function down(): void
    {
        Schema::table('debts', function (Blueprint $table) {
            $table->dropColumn(['creditor_document', 'contracted_on']);
        });
    }
};
