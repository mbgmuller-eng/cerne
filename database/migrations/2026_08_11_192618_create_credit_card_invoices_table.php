<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('credit_card_invoices', function (Blueprint $table) {
            $table->uuid('id')->primary();

            // profile_id não está na especificação, mas é o que permite o
            // escopo de tenancy alcançar a fatura sem join com o cartão.
            $table->foreignUuid('profile_id')->constrained('financial_profiles')->cascadeOnDelete();
            $table->foreignUuid('credit_card_id')->constrained('credit_cards')->cascadeOnDelete();

            $table->unsignedSmallInteger('year');
            $table->unsignedTinyInteger('month');
            $table->date('closing_date');
            $table->date('due_date');
            $table->decimal('total_amount', 15, 2)->default(0);
            $table->string('status', 20)->default('open');

            $table->date('paid_at')->nullable();
            $table->decimal('paid_amount', 15, 2)->nullable();
            $table->foreignUuid('paid_from_account_id')->nullable()->constrained('bank_accounts')->nullOnDelete();

            $table->timestamps();

            // Uma fatura por ciclo — é o que torna a geração idempotente.
            $table->unique(['credit_card_id', 'year', 'month']);
            $table->index(['profile_id', 'year', 'month']);
            $table->index(['profile_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('credit_card_invoices');
    }
};
