<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Catálogo COMPARTILHADO de exercícios (Cerne Saúde › Academia): nome,
 * grupo muscular, tipo de medida e foto — pronto pra usar ao montar um
 * plano. Mesmo padrão de expense_categories/banks (BelongsToProfileOrShared):
 * profile_id nulo = entrada padrão do sistema, visível a todo mundo.
 *
 * NÃO é dado de saúde pessoal — é referência genérica ("Supino Reto com
 * Barra trabalha peito"), o mesmo tipo de informação que já existe em
 * qualquer app ou livro de treino. Por isso NÃO usa IsPersonalHealthData
 * nem PersonalHealthScope: quem escolhe um exercício DAQUI pra dentro do
 * PRÓPRIO plano é que gera o dado pessoal (GymExercise, esse sim
 * escopado). Ver GymExerciseCatalog.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('gym_exercise_catalog', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('profile_id')->nullable()->constrained('financial_profiles')->cascadeOnDelete();
            $table->string('name', 120);
            $table->string('muscle_group', 20);
            $table->string('measure_type', 20)->default('load_reps');
            // Texto livre ("Barra livre", "Halteres e cabo") — não é o
            // gym_equipment pessoal, é só a dica de equipamento do catálogo.
            $table->string('equipment_hint', 120)->nullable();
            $table->text('notes')->nullable();
            $table->string('image_path')->nullable();
            $table->string('image_path_2')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['profile_id', 'muscle_group']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('gym_exercise_catalog');
    }
};
