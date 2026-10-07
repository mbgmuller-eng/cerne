<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Dados fiscais de quem paga a assinatura: nome completo (ou razão social),
 * CPF/CNPJ, data de nascimento e endereço. Existem para emitir a nota fiscal de
 * serviço (obrigação legal) e ficam do lado do Cerne, não só na Asaas.
 *
 * É por USUÁRIO (quem paga), não por perfil financeiro: um profissional paga a
 * própria assinatura sem ter perfil algum.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('billing_details', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->unique()->constrained('users')->cascadeOnDelete();
            $table->string('full_name', 150);
            $table->string('document', 14);
            $table->date('birth_date')->nullable();
            $table->char('postal_code', 8);
            $table->string('street', 120);
            $table->string('number', 20);
            $table->string('complement', 60)->nullable();
            $table->string('neighborhood', 80);
            $table->string('city', 80);
            $table->char('state', 2);
            $table->timestamps();
        });

        // Quando a configuração de nota fiscal foi aplicada na assinatura da
        // Asaas. Nulo com a emissão ligada = ainda falta (o job tenta de novo).
        Schema::table('subscriptions', function (Blueprint $table): void {
            $table->timestamp('invoice_settings_at')->nullable()->after('pix_authorization_status');
        });
    }

    public function down(): void
    {
        Schema::table('subscriptions', function (Blueprint $table): void {
            $table->dropColumn('invoice_settings_at');
        });

        Schema::dropIfExists('billing_details');
    }
};
