<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cobranças mensais do Pix Automático no modo MANUAL: o Cerne cria cada uma
 * pela API, então precisa lembrar quais ciclos já cobrou. O cron da
 * hospedagem compartilhada pode disparar repetido ou concorrente, e a
 * proteção é o índice único (assinatura, ano, mês), não um `if (existe)` —
 * regra 4 do CLAUDE.md.
 *
 * `claimed_at` é a reserva do ciclo: só quem consegue marcá-la chama a API,
 * para duas execuções simultâneas não criarem duas cobranças na Asaas.
 * `retry_*` guardam a retentativa de uma instrução recusada (até 3, em dias
 * diferentes) que um job posterior pede à Asaas.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('subscription_charges', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('subscription_id')->constrained('subscriptions')->cascadeOnDelete();
            $table->unsignedSmallInteger('year');
            $table->unsignedTinyInteger('month');
            $table->date('due_date');
            $table->decimal('value', 15, 2);
            $table->string('asaas_payment_id', 64)->nullable()->index();
            $table->string('asaas_instruction_id', 64)->nullable();
            $table->timestamp('claimed_at')->nullable();
            $table->unsignedTinyInteger('retry_attempts')->default(0);
            $table->date('retry_due_date')->nullable();
            $table->timestamp('retry_requested_at')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamps();

            $table->unique(['subscription_id', 'year', 'month']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subscription_charges');
    }
};
