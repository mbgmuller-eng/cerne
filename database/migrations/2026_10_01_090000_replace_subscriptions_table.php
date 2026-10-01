<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A tabela `subscriptions` original (migration 2026_08_10_195246) era um
 * resquício da especificação técnica — plano plano-a-plano (free/basic/
 * premium/consultant), nunca ligada a nenhuma tela, rota ou gate. O
 * modelo de pacote por módulo (ver SubscriptionBundle) não encaixa nesse
 * desenho, e não existe NENHUM dado real nela pra migrar — por isso
 * dropar e recriar em vez de alterar.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('subscriptions');

        Schema::create('subscriptions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            // Quem PAGA — cliente assinando por si, ou profissional
            // cobrindo os clientes vinculados dele (ver SubscriptionKind).
            $table->foreignUuid('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('kind', 20);
            $table->string('bundle', 30);
            $table->string('status', 20);
            // Da última cobrança CONFIRMADA — alimenta "renova em X" e a
            // carência de PastDue (ver Subscription::isCurrent()).
            $table->date('current_period_ends_at')->nullable();
            // Identidade do cliente na Asaas (users.asaas_customer_id) é
            // uma por PESSOA — não duplica aqui. Isto é só a assinatura.
            $table->string('asaas_subscription_id')->nullable()->index();
            $table->date('started_at');
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamps();

            // Exatamente a consulta que EntitlementService faz.
            $table->index(['user_id', 'kind', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subscriptions');
    }
};
