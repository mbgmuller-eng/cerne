<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Estorno (cashback, contestação de compra revertida...) não é uma
     * despesa comum: não tem necessidade nem categoria, e o valor entra
     * negativo pra abater o total da fatura/do mês em vez de somar. Por
     * isso `necessity`/`category_id` precisam aceitar nulo — só pra essas
     * linhas, marcadas por `is_refund` (ver validação em ExpenseRecord).
     */
    public function up(): void
    {
        Schema::table('expense_records', function (Blueprint $table) {
            $table->boolean('is_refund')->default(false)->after('necessity');
        });

        DB::statement('ALTER TABLE expense_records MODIFY necessity VARCHAR(20) NULL');
        DB::statement('ALTER TABLE expense_records MODIFY category_id CHAR(36) NULL');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE expense_records MODIFY category_id CHAR(36) NOT NULL');
        DB::statement('ALTER TABLE expense_records MODIFY necessity VARCHAR(20) NOT NULL');

        Schema::table('expense_records', function (Blueprint $table) {
            $table->dropColumn('is_refund');
        });
    }
};
