<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Passo a passo (roteiro) do acompanhamento: etapas com itens, por relação consultor + cliente, e os modelos do consultor.
 *
 * O roteiro de um cliente é uma CÓPIA do modelo na hora em que é aplicado: mexer no modelo depois não muda quem já o usa.
 * Item automático (`auto_check`) é marcado pelo que o app já sabe do cliente; item manual, pelo consultor.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('engagement_plan_stages', function (Blueprint $table): void {
            $table->char('id', 36)->primary();
            $table->char('profile_id', 36);
            $table->char('consultant_id', 36);
            $table->unsignedSmallInteger('position')->default(0);
            $table->string('title', 120);
            $table->text('description')->nullable();
            $table->timestamps();

            $table->foreign('profile_id')->references('id')->on('financial_profiles')->cascadeOnDelete();
            $table->foreign('consultant_id')->references('id')->on('users')->cascadeOnDelete();
            $table->index(['profile_id', 'consultant_id', 'position'], 'eng_stages_relation_idx');
        });

        Schema::create('engagement_plan_items', function (Blueprint $table): void {
            $table->char('id', 36)->primary();
            $table->char('profile_id', 36);
            $table->char('consultant_id', 36);
            $table->char('stage_id', 36);
            $table->unsignedSmallInteger('position')->default(0);
            $table->string('title', 160);
            $table->string('auto_check', 30)->nullable();
            $table->dateTime('done_at')->nullable();
            $table->string('done_source', 10)->nullable();
            $table->timestamps();

            $table->foreign('profile_id')->references('id')->on('financial_profiles')->cascadeOnDelete();
            $table->foreign('consultant_id')->references('id')->on('users')->cascadeOnDelete();
            $table->foreign('stage_id')->references('id')->on('engagement_plan_stages')->cascadeOnDelete();
            $table->index(['stage_id', 'position'], 'eng_items_stage_idx');
        });

        Schema::create('engagement_plan_templates', function (Blueprint $table): void {
            $table->char('id', 36)->primary();
            $table->char('consultant_id', 36);
            $table->string('name', 120);
            $table->text('body');
            $table->timestamps();

            $table->foreign('consultant_id')->references('id')->on('users')->cascadeOnDelete();
            $table->index('consultant_id', 'eng_templates_consultant_idx');
        });
    }

    public function down(): void
    {
        foreach (['engagement_plan_templates', 'engagement_plan_items', 'engagement_plan_stages'] as $tabela) {
            Schema::dropIfExists($tabela);
        }
    }
};
