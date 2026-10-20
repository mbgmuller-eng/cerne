<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Troca "só por hoje": numa sessão, o exercício de um item do treino é outro, sem mexer no plano. Quem não quiser
        // levar a troca para as próximas vezes (equipamento ocupado, dor do dia) usa isto; a troca definitiva muda o próprio item.
        Schema::create('gym_session_swaps', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('profile_id')->constrained('financial_profiles')->cascadeOnDelete();
            $table->foreignUuid('member_id')->constrained('profile_members')->cascadeOnDelete();
            $table->foreignUuid('session_id')->constrained('gym_sessions')->cascadeOnDelete();
            $table->foreignUuid('workout_exercise_id')->constrained('gym_workout_exercises')->cascadeOnDelete();
            $table->foreignUuid('exercise_id')->constrained('gym_exercises')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['session_id', 'workout_exercise_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('gym_session_swaps');
    }
};
