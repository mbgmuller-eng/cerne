<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `cpf_cnpj` é obrigatório pra Asaas criar um cliente (POST /v3/customers
 * exige cpfCnpj) — ninguém tinha isso cadastrado antes porque a cobrança
 * não existia. `asaas_customer_id` fica aqui (não em `subscriptions`)
 * porque é identidade da PESSOA, uma só, reaproveitada em toda assinatura
 * que ela já teve ou vier a ter — `subscriptions.asaas_subscription_id` é
 * que muda a cada assinatura.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->string('cpf_cnpj', 18)->nullable()->after('phone');
            $table->string('asaas_customer_id')->nullable()->after('cpf_cnpj')->index();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn(['cpf_cnpj', 'asaas_customer_id']);
        });
    }
};
