<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Regra de orçamento: quanto da receita do mês vai para cada área (essencial, supérfluo, investimentos) e, dentro de investimentos, para cada
 * grupo e item. A regra é UMA árvore guardada em JSON (`structure`, ver App\Support\Budget\BudgetStructure): são poucas dezenas de nós, sempre lidos
 * juntos, e o mesmo formato serve ao modelo do consultor, que não pode apontar para ids de categoria de um perfil só.
 *
 * `budget_rules`: uma versão por perfil e por mês de início (índice único). A regra de um mês é a mais recente com início até ele, então mudar os
 * percentuais vale daqui para frente e não reescreve os meses passados.
 *
 * `budget_rule_templates`: modelo do consultor (metodologia dele, sem perfil). Aplicar a um cliente COPIA a estrutura.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('budget_rules', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('profile_id')->constrained('financial_profiles')->cascadeOnDelete();
            $table->unsignedSmallInteger('effective_year');
            $table->unsignedTinyInteger('effective_month');
            $table->json('structure');
            $table->foreignUuid('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            // Regra 4: salvar duas vezes o mesmo mês (duas abas, ou o consultor e o cliente) atualiza a mesma linha, nunca cria duas.
            $table->unique(['profile_id', 'effective_year', 'effective_month'], 'budget_rules_profile_month_unique');
        });

        Schema::create('budget_rule_templates', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('consultant_id')->constrained('users')->cascadeOnDelete();
            $table->string('name', 120);
            $table->string('description', 500)->nullable();
            $table->json('structure');
            $table->timestamps();

            $table->index('consultant_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('budget_rule_templates');
        Schema::dropIfExists('budget_rules');
    }
};
