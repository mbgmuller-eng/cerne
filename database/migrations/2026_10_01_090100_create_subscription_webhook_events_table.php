<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Idempotência dos webhooks da Asaas — entrega "at-least-once", o mesmo
 * evento pode chegar mais de uma vez. Índice único em `asaas_event_id`,
 * não um `if (existe)` — mesma regra 4 do CLAUDE.md (índice único resolve
 * a corrida, "if existe" perde).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('subscription_webhook_events', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('asaas_event_id')->unique();
            $table->string('event_type', 60);
            $table->timestamp('processed_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subscription_webhook_events');
    }
};
