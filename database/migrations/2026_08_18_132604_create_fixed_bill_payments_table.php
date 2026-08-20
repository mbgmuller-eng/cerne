<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fixed_bill_payments', function (Blueprint $table) {
            $table->uuid('id')->primary();

            // profile_id não está na especificação, mas sem ele o escopo de
            // tenancy precisaria de um join com fixed_bills a cada query.
            $table->foreignUuid('profile_id')->constrained('financial_profiles')->cascadeOnDelete();
            $table->foreignUuid('fixed_bill_id')->constrained('fixed_bills')->cascadeOnDelete();

            $table->unsignedSmallInteger('year');
            $table->unsignedTinyInteger('month');
            $table->date('due_date');

            $table->decimal('amount_paid', 15, 2)->nullable();
            $table->string('status', 20)->default('pending');
            $table->date('paid_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            // É este índice que torna a geração mensal idempotente: o cron
            // da hospedagem compartilhada pode disparar concorrente.
            $table->unique(['fixed_bill_id', 'year', 'month']);
            $table->index(['profile_id', 'year', 'month']);
            $table->index(['profile_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fixed_bill_payments');
    }
};
