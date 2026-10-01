<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Qual dos dois meios aceitos (cartão ou Pix) a pessoa escolheu — é o que
 * SubscriptionReminderService usa pra saber quem precisa do aviso de
 * vencimento do Pix (cartão não tem esse problema, ambos clicam pra
 * pagar, mas só o Pix não tem nenhum lembrete nativo da Asaas).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('subscriptions', function (Blueprint $table): void {
            $table->string('billing_type', 20)->nullable()->after('bundle');
        });
    }

    public function down(): void
    {
        Schema::table('subscriptions', function (Blueprint $table): void {
            $table->dropColumn('billing_type');
        });
    }
};
