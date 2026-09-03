<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Opcional: quando preenchido, a regra só casa se o valor do item bater
     * exatamente — caso real é o PIX mensal que a pessoa manda pra si
     * mesma (mesma descrição de vários outros PIX, mas sempre o mesmo
     * valor). Sem isso, "PIX" sozinho como padrão casaria com qualquer PIX.
     */
    public function up(): void
    {
        Schema::table('expense_categorization_rules', function (Blueprint $table) {
            $table->decimal('amount', 15, 2)->nullable()->after('pattern');
        });

        Schema::table('income_categorization_rules', function (Blueprint $table) {
            $table->decimal('amount', 15, 2)->nullable()->after('pattern');
        });
    }

    public function down(): void
    {
        Schema::table('expense_categorization_rules', function (Blueprint $table) {
            $table->dropColumn('amount');
        });

        Schema::table('income_categorization_rules', function (Blueprint $table) {
            $table->dropColumn('amount');
        });
    }
};
