<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Base (quanto a pessoa colocou) e resgates já feitos de um investimento, para a performance de quem rende na própria moeda.
 *
 * Em USDT, BTC e ETH a base e o resgate ficam na moeda de origem (`invested_native`, `realized_native`) e o desempenho é medido em
 * quantidade, sem o sobe e desce da cotação; `invested_amount` e `realized_amount` (em reais) são essas quantidades vezes a cotação do
 * dia, refeitas pela rotina diária junto com `current_amount`. No real, só `realized_amount` é novo: `invested_amount` já era a base.
 *
 * Ganho = valor atual + já resgatado - base.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('investment_records', function (Blueprint $table) {
            $table->decimal('invested_native', 24, 10)->nullable()->after('native_amount');
            $table->decimal('realized_native', 24, 10)->nullable()->after('invested_native');
            $table->decimal('realized_amount', 15, 2)->nullable()->after('invested_amount');
        });
    }

    public function down(): void
    {
        Schema::table('investment_records', function (Blueprint $table) {
            $table->dropColumn(['invested_native', 'realized_native', 'realized_amount']);
        });
    }
};
