<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Pix Automático: a autorização que o cliente concede ao próprio banco para
 * débitos mensais. O status espelha o da Asaas (CREATED, ACTIVE, CANCELLED,
 * REFUSED, EXPIRED) e é atualizado pelos webhooks — só com ACTIVE o job de
 * cobrança mensal gera a cobrança do ciclo.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('subscriptions', function (Blueprint $table): void {
            $table->string('asaas_pix_authorization_id', 64)->nullable()->after('asaas_subscription_id')->index();
            $table->string('pix_authorization_status', 20)->nullable()->after('asaas_pix_authorization_id');
        });
    }

    public function down(): void
    {
        Schema::table('subscriptions', function (Blueprint $table): void {
            $table->dropIndex(['asaas_pix_authorization_id']);
            $table->dropColumn(['asaas_pix_authorization_id', 'pix_authorization_status']);
        });
    }
};
