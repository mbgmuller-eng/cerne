<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('investment_records', function (Blueprint $table) {
            // Última cotação conhecida da cota e o dia a que ela se refere. O valor do ativo
            // (current_amount) passa a ser quantidade x cotação; compra, venda e "atualizar
            // cotação" movem estes dois campos. Nulos em ativo sem cotas (renda fixa etc.).
            $table->decimal('current_price', 15, 6)->nullable()->after('quantity');
            $table->date('price_date')->nullable()->after('current_price');
        });
    }

    public function down(): void
    {
        Schema::table('investment_records', function (Blueprint $table) {
            $table->dropColumn(['current_price', 'price_date']);
        });
    }
};
