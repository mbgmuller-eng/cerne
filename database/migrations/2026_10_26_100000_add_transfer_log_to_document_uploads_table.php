<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Linha de extrato que é transferência entre contas do próprio perfil: só o saldo da conta do extrato mexe, nada vira
     * receita nem despesa (senão o fluxo de caixa contaria o mesmo dinheiro duas vezes, uma em cada conta). Como não nasce
     * lançamento, o rastro fica aqui: o que foi, quanto, para qual direção e em qual conta.
     */
    public function up(): void
    {
        Schema::table('document_uploads', function (Blueprint $table) {
            $table->json('transfer_log')->nullable()->after('card_payment_log');
        });
    }

    public function down(): void
    {
        Schema::table('document_uploads', function (Blueprint $table) {
            $table->dropColumn('transfer_log');
        });
    }
};
