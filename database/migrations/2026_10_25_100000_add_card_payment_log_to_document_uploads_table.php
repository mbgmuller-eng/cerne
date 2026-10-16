<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Pagamento de fatura de cartão que veio numa linha de extrato: ou dá baixa numa fatura cadastrada, ou, sem fatura
     * cadastrada, só mexe no saldo da conta, sem virar despesa. Nos dois casos não nasce um lançamento de despesa, então
     * o rastro fica aqui: o que foi pago, quanto, de qual conta e (quando houve) qual fatura recebeu a baixa.
     */
    public function up(): void
    {
        Schema::table('document_uploads', function (Blueprint $table) {
            $table->json('card_payment_log')->nullable()->after('excluded_item_indices');
        });
    }

    public function down(): void
    {
        Schema::table('document_uploads', function (Blueprint $table) {
            $table->dropColumn('card_payment_log');
        });
    }
};
