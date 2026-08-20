<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * O "molde" de uma conta que se repete todo mês (aluguel, internet,
     * plano de saúde). Cada vencimento concreto vira um fixed_bill_payments.
     */
    public function up(): void
    {
        Schema::create('fixed_bills', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('profile_id')->constrained('financial_profiles')->cascadeOnDelete();

            // Nulo = conta da família.
            $table->foreignUuid('member_id')->nullable()->constrained('profile_members')->nullOnDelete();

            $table->string('name', 255);
            $table->decimal('amount', 15, 2);
            $table->unsignedTinyInteger('due_day');

            $table->foreignUuid('bank_account_id')->nullable()->constrained('bank_accounts')->nullOnDelete();
            $table->foreignUuid('credit_card_id')->nullable()->constrained('credit_cards')->nullOnDelete();
            $table->foreignUuid('category_id')->nullable()->constrained('expense_categories')->nullOnDelete();
            $table->foreignUuid('subcategory_id')->nullable()->constrained('expense_subcategories')->nullOnDelete();

            // Conta de valor variável (luz, água): o `amount` é só uma
            // estimativa e o valor real é informado no pagamento.
            $table->boolean('is_variable')->default(false);

            $table->boolean('is_active')->default(true);
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['profile_id', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fixed_bills');
    }
};
