<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Espelho de fixed_bills do lado da receita: salário, aluguel recebido,
     * qualquer entrada que se repete. Mesma estrutura de periodicidade
     * (recurrence/due_day/due_weekday/due_month) — ver RecurrenceType.
     */
    public function up(): void
    {
        Schema::create('recurring_incomes', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('profile_id')->constrained('financial_profiles')->cascadeOnDelete();

            // Nulo = receita da família.
            $table->foreignUuid('member_id')->nullable()->constrained('profile_members')->nullOnDelete();

            $table->string('name', 255);
            $table->decimal('amount', 15, 2);

            $table->string('recurrence', 20)->default('monthly');
            $table->unsignedTinyInteger('due_day')->nullable();
            $table->unsignedTinyInteger('due_weekday')->nullable();
            $table->unsignedTinyInteger('due_month')->nullable();

            $table->foreignUuid('bank_account_id')->nullable()->constrained('bank_accounts')->nullOnDelete();
            $table->foreignUuid('category_id')->nullable()->constrained('income_categories')->nullOnDelete();

            // Receita de valor variável (comissão, freela): o `amount` é só
            // estimativa, valor real informado ao confirmar o recebimento.
            $table->boolean('is_variable')->default(false);

            $table->boolean('is_active')->default(true);
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['profile_id', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('recurring_incomes');
    }
};
