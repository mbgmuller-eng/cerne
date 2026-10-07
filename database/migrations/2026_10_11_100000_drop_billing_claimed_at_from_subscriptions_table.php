<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A reserva da criação antecipada da assinatura na Asaas (migration
 * 2026_10_10) deixou de existir: a assinatura agora só nasce lá quando a
 * pessoa decide pagar, por uma ação dela na tela de assinatura.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('subscriptions', function (Blueprint $table): void {
            $table->dropColumn('billing_claimed_at');
        });
    }

    public function down(): void
    {
        Schema::table('subscriptions', function (Blueprint $table): void {
            $table->timestamp('billing_claimed_at')->nullable()->after('asaas_pix_authorization_id');
        });
    }
};
