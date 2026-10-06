<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A assinatura na Asaas só é criada perto do fim do teste grátis
 * (SubscriptionBillingService). Esta coluna é a reserva dessa criação: só quem
 * consegue marcá-la chama a Asaas, para o cron da hospedagem compartilhada
 * (que pode disparar repetido ou concorrente) nunca criar duas assinaturas
 * para a mesma pessoa. Regra 4 do CLAUDE.md: a proteção é um UPDATE
 * condicional, não um `if (existe)`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('subscriptions', function (Blueprint $table): void {
            $table->timestamp('billing_claimed_at')->nullable()->after('asaas_pix_authorization_id');
        });
    }

    public function down(): void
    {
        Schema::table('subscriptions', function (Blueprint $table): void {
            $table->dropColumn('billing_claimed_at');
        });
    }
};
