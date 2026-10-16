<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Desdobramento e grupamento guardam a PROPORÇÃO ("cada 1 vira 2"), não a quantidade final: refazer a posição pela ordem
     * das datas (lançamento retroativo) só dá certo se o evento multiplicar a quantidade que existia naquele dia.
     * Eventos antigos, sem proporção, seguem lendo `quantity` como a quantidade final.
     */
    public function up(): void
    {
        Schema::table('investment_transactions', function (Blueprint $table) {
            $table->decimal('ratio_from', 15, 6)->nullable()->after('unit_price');
            $table->decimal('ratio_to', 15, 6)->nullable()->after('ratio_from');
        });
    }

    public function down(): void
    {
        Schema::table('investment_transactions', function (Blueprint $table) {
            $table->dropColumn(['ratio_from', 'ratio_to']);
        });
    }
};
