<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Idempotência dos avisos de cobrança disparados por rotina agendada: o
 * cron da hospedagem pode rodar duas vezes no mesmo dia, e "já avisei?"
 * com `if (existe)` perde a corrida. Índice único em (assinatura, tipo,
 * data de referência) — mesma regra 4 do CLAUDE.md.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('subscription_notices', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('subscription_id')->constrained('subscriptions')->cascadeOnDelete();
            $table->string('kind', 40);
            $table->date('reference_date');
            $table->timestamp('sent_at');

            $table->unique(['subscription_id', 'kind', 'reference_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subscription_notices');
    }
};
