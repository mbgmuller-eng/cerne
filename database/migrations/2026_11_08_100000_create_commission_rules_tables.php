<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Regras de comissionamento do profissional: quanto cada seguradora (e, nas próximas fases, administradora de
        // consórcio e empresa de investimento) paga a ele. O que vale para uma apólice é a regra mais específica.
        Schema::create('commission_rules', function (Blueprint $table) {
            $table->char('id', 36)->primary();
            $table->foreignUuid('professional_id')->constrained('users')->cascadeOnDelete();
            $table->string('kind', 20)->default('insurance');
            $table->string('counterparty', 120);
            $table->string('counterparty_key', 120);
            // Tipo de seguro a que a regra se aplica; vazio = todos. type_key existe só para o índice único (NULL não conflita).
            $table->string('insurance_type', 30)->nullable();
            $table->string('type_key', 30)->default('*');
            $table->decimal('first_year_percent', 7, 3)->nullable();
            // Vazio = igual ao 1º ano (há seguradora que paga o mesmo todo ano).
            $table->decimal('renewal_percent', 7, 3)->nullable();
            $table->string('agency_type', 10)->nullable();
            $table->decimal('agency_percent', 7, 3)->nullable();
            $table->decimal('agency_amount', 15, 2)->nullable();
            // A seguradora só paga depois que o cliente paga a parcela: quantos dias depois do vencimento ela costuma cair.
            $table->unsignedSmallInteger('payout_delay_days')->default(0);
            $table->boolean('is_active')->default(true);
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(['professional_id', 'kind', 'counterparty_key', 'type_key'], 'commission_rules_unique_target');
        });

        // Apólice em que o profissional tem comissão. Nasce sozinha para a que ele vendeu (corretor vinculado, ou cotação
        // de um lead dele) e o consultor pode incluir outras à mão.
        Schema::create('policy_commissions', function (Blueprint $table) {
            $table->char('id', 36)->primary();
            $table->foreignUuid('professional_id')->constrained('users')->cascadeOnDelete();
            $table->char('insurance_policy_id', 36);
            $table->string('origin', 10)->default('auto');
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->foreign('insurance_policy_id')->references('id')->on('insurance_policies')->cascadeOnDelete();
            $table->unique(['professional_id', 'insurance_policy_id']);
        });

        // Chave de idempotência dos recebimentos gerados por regra (apólice, consórcio...): a rotina nunca cria duas vezes o mesmo.
        Schema::table('income_entries', function (Blueprint $table) {
            $table->string('origin_key', 120)->nullable()->after('fee_agreement_id');
            $table->unique(['professional_id', 'origin_key']);
        });
    }

    public function down(): void
    {
        Schema::table('income_entries', function (Blueprint $table) {
            $table->dropUnique(['professional_id', 'origin_key']);
            $table->dropColumn('origin_key');
        });
        Schema::dropIfExists('policy_commissions');
        Schema::dropIfExists('commission_rules');
    }
};
