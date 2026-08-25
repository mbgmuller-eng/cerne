<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A meta da reserva de paz deixa de ser digitada à mão (custo médio +
 * meses) e passa a ser calculada: média dos gastos essenciais dos últimos
 * meses com dado (até 12) x meses conforme o tipo de atuação. Os dois
 * campos manuais saem; entra o tipo de atuação, que é o único dado que o
 * consultor ainda precisa informar.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('investor_profiles', function (Blueprint $table) {
            $table->string('employment_type', 30)->nullable()->after('investor_type');
            $table->dropColumn(['monthly_cost_average', 'months_reserve_target']);
        });
    }

    public function down(): void
    {
        Schema::table('investor_profiles', function (Blueprint $table) {
            $table->dropColumn('employment_type');
            $table->decimal('monthly_cost_average', 15, 2)->nullable();
            $table->unsignedTinyInteger('months_reserve_target')->nullable();
        });
    }
};
