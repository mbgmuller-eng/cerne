<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * O "cabeçalho" de uma compra parcelada.
     *
     * A compra em si NÃO vira um lançamento de valor total: cada parcela
     * é um expense_records próprio, na fatura do seu ciclo (seção 4 da
     * especificação). Este registro é o que amarra as parcelas entre si.
     */
    public function up(): void
    {
        Schema::create('installment_groups', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('profile_id')->constrained('financial_profiles')->cascadeOnDelete();
            $table->string('description', 255);
            $table->decimal('total_amount', 15, 2);
            $table->unsignedSmallInteger('total_installments');

            // Valor "de vitrine" da parcela. A soma real das parcelas pode
            // diferir deste valor x N por centavos — a última absorve a sobra.
            $table->decimal('installment_amount', 15, 2);

            $table->date('first_installment_date');
            $table->foreignUuid('credit_card_id')->nullable()->constrained('credit_cards')->cascadeOnDelete();
            $table->foreignUuid('created_by_user_id')->constrained('users');
            $table->timestamps();

            $table->index('profile_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('installment_groups');
    }
};
