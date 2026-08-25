<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Espelho de fixed_bill_payments: a ocorrência concreta de uma receita
     * recorrente numa data. (recurring_income_id, due_date) é a chave de
     * idempotência — mesmo raciocínio da conta fixa (CLAUDE.md regra 4).
     */
    public function up(): void
    {
        Schema::create('recurring_income_occurrences', function (Blueprint $table) {
            $table->uuid('id')->primary();

            $table->foreignUuid('profile_id')->constrained('financial_profiles')->cascadeOnDelete();
            $table->foreignUuid('recurring_income_id')->constrained('recurring_incomes')->cascadeOnDelete();

            $table->unsignedSmallInteger('year');
            $table->unsignedTinyInteger('month');
            $table->date('due_date');

            $table->decimal('amount_received', 15, 2)->nullable();
            $table->string('status', 20)->default('pending');
            $table->date('received_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(['recurring_income_id', 'due_date']);
            $table->index(['profile_id', 'year', 'month']);
            $table->index(['profile_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('recurring_income_occurrences');
    }
};
