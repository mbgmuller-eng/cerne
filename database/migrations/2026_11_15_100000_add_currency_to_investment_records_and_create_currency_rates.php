<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Investimento em moeda que não é o real (USDT, BTC, ETH).
 *
 * `current_amount` continua SEMPRE em reais: é ele que todas as somas, o painel, a carteira do consultor e o cálculo de honorários leem,
 * e misturar 0,05 BTC com R$ 1.000 em uma SUM() daria um número errado sem nenhum aviso. A quantidade na moeda de origem fica em
 * `native_amount` e `current_amount` é essa quantidade vezes a cotação do dia (`currency_rates`, atualizada pela rotina diária).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('investment_records', function (Blueprint $table) {
            $table->string('currency', 8)->default('BRL')->after('asset_class');
            // 10 casas: BTC e ETH vêm com 8 da corretora e sobra folga para a conta; é quantidade, não dinheiro em reais.
            $table->decimal('native_amount', 24, 10)->nullable()->after('current_amount');

            $table->index(['profile_id', 'currency']);
        });

        Schema::create('currency_rates', function (Blueprint $table) {
            $table->id();
            $table->string('currency', 8);
            // Quanto vale 1 unidade da moeda, em reais.
            $table->decimal('rate_brl', 20, 8);
            $table->date('rate_date');
            $table->string('source', 30);
            $table->timestamps();

            // Uma cotação por moeda por dia: a rotina pode rodar de novo (ou duas vezes ao mesmo tempo) sem duplicar.
            $table->unique(['currency', 'rate_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('currency_rates');

        Schema::table('investment_records', function (Blueprint $table) {
            $table->dropIndex(['profile_id', 'currency']);
            $table->dropColumn(['currency', 'native_amount']);
        });
    }
};
