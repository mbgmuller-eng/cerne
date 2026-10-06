<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fila de sugestões de exercício para o catálogo compartilhado da Academia.
 *
 * É ANÔNIMA de propósito: treino é dado de Saúde (nem consultor nem corretor
 * enxergam), então nada aqui diz QUEM pediu — sem profile_id, member_id ou
 * user_id. O admin vê só o nome, o grupo, o tipo e "pedido N vezes".
 *
 * `normalized_name` (minúsculo, sem acento) é único: o contador sobe por
 * índice, não por if (existe), porque duas pessoas podem digitar o mesmo
 * exercício ao mesmo tempo (regra 4 do CLAUDE.md).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('gym_exercise_suggestions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name', 120);
            $table->string('normalized_name', 120)->unique();
            $table->string('muscle_group', 20);
            $table->string('measure_type', 20)->default('load_reps');
            $table->unsignedInteger('times_suggested')->default(1);
            $table->timestamp('dismissed_at')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('gym_exercise_suggestions');
    }
};
