<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Movimentações do ativo. É a partir desta tabela que o preço médio é
     * recalculado a cada compra.
     *
     * A especificação observa que estes campos já bastam para um futuro
     * módulo de apuração de IR sobre renda variável — daí guardarmos as
     * taxas separadas e a data de liquidação.
     */
    public function up(): void
    {
        Schema::create('investment_transactions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('profile_id')->constrained('financial_profiles')->cascadeOnDelete();
            $table->foreignUuid('member_id')->constrained('profile_members')->cascadeOnDelete();
            $table->foreignUuid('investment_id')->constrained('investment_records')->cascadeOnDelete();

            $table->string('transaction_type', 20);
            $table->decimal('quantity', 15, 6)->nullable();
            $table->decimal('unit_price', 15, 6)->nullable();
            $table->decimal('total_amount', 15, 2);
            $table->decimal('broker_fee', 10, 2)->nullable();
            $table->decimal('other_fees', 10, 2)->nullable();

            // Bruto menos taxas. É este valor que entra no preço médio:
            // a corretagem faz parte do custo de aquisição.
            $table->decimal('net_amount', 15, 2);

            $table->date('operation_date');
            $table->date('settlement_date')->nullable();

            $table->uuid('source_document_id')->nullable();
            $table->foreignUuid('created_by_user_id')->constrained('users');
            $table->timestamps();

            $table->index(['profile_id', 'investment_id']);
            $table->index(['investment_id', 'operation_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('investment_transactions');
    }
};
