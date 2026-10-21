<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Versão dos Termos e da Política que a pessoa aceitou. Quando a versão atual muda, todo mundo aceita de novo no próximo acesso.
            $table->string('terms_version', 20)->nullable()->after('terms_accepted_at');
            // Consentimento ESPECÍFICO para dado de saúde (LGPD art. 11, I): separado do aceite geral e revogável.
            $table->dateTime('health_consent_at')->nullable()->after('terms_version');
            $table->string('health_consent_version', 20)->nullable()->after('health_consent_at');
            // Pedido de exclusão da própria conta: executado depois de uma carência, para dar tempo de desistir (e de barrar um pedido
            // feito por quem tomou conta da sessão).
            $table->dateTime('deletion_requested_at')->nullable()->after('health_consent_version');
            $table->dateTime('deletion_due_at')->nullable()->after('deletion_requested_at');

            $table->index('deletion_due_at');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex(['deletion_due_at']);
            $table->dropColumn(['terms_version', 'health_consent_at', 'health_consent_version', 'deletion_requested_at', 'deletion_due_at']);
        });
    }
};
