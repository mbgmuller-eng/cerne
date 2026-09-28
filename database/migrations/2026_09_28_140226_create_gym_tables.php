<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cerne Saúde › Academia. Toda tabela carrega profile_id E member_id
 * (inclusive as filhas): o escopo PersonalHealthScope filtra por member_id
 * direto na linha, sem join — um eager load de série ou de exercício do
 * treino não pode vazar o que o pai esconderia.
 *
 * member_id é NOT NULL e cascadeia: treino pertence a uma pessoa, não à
 * família (diferente de apólice, onde nulo = seguro familiar).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('gym_exercises', function (Blueprint $table) {
            $this->owner($table);
            $table->string('name', 120);
            $table->string('muscle_group', 20);
            $table->string('measure_type', 20)->default('load_reps');
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            // Um "Supino Reto Halteres" por pessoa: o histórico depende
            // de o exercício ter uma identidade só.
            $table->unique(['member_id', 'name']);
        });

        Schema::create('gym_equipment', function (Blueprint $table) {
            $this->owner($table);
            $table->string('name', 80);
            $table->timestamps();

            $table->unique(['member_id', 'name']);
        });

        Schema::create('gym_plans', function (Blueprint $table) {
            $this->owner($table);
            $table->string('name', 80);
            $table->boolean('is_active')->default(true);
            $table->date('started_on')->nullable();
            $table->date('ended_on')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['member_id', 'is_active']);
        });

        Schema::create('gym_workouts', function (Blueprint $table) {
            $this->owner($table);
            $table->foreignUuid('plan_id')->constrained('gym_plans')->cascadeOnDelete();
            $table->string('name', 80);
            $table->string('focus', 160)->nullable();
            // Ordem da rotação (A → B → C).
            $table->unsignedSmallInteger('position');
            $table->timestamps();

            $table->unique(['plan_id', 'position']);
        });

        Schema::create('gym_workout_exercises', function (Blueprint $table) {
            $this->owner($table);
            $table->foreignUuid('workout_id')->constrained('gym_workouts')->cascadeOnDelete();
            $table->foreignUuid('exercise_id')->constrained('gym_exercises')->cascadeOnDelete();
            $table->unsignedSmallInteger('position');
            $table->unsignedSmallInteger('target_sets')->default(3);
            // Faixa de repetições: bater o teto em todas as séries é o
            // sinal pra subir a carga (progressão dupla).
            $table->unsignedSmallInteger('target_reps_min')->nullable();
            $table->unsignedSmallInteger('target_reps_max')->nullable();
            $table->unsignedSmallInteger('target_duration_seconds')->nullable();
            // Nulo em exercício medido em tempo (cronômetro, sem pausa).
            $table->unsignedSmallInteger('rest_seconds')->nullable();
            $table->foreignUuid('default_equipment_id')->nullable()->constrained('gym_equipment')->nullOnDelete();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(['workout_id', 'position']);
        });

        Schema::create('gym_sessions', function (Blueprint $table) {
            $this->owner($table);
            $table->foreignUuid('workout_id')->constrained('gym_workouts')->cascadeOnDelete();
            // A data é gravada no momento do registro — o histórico de
            // papel tinha data aproximada justamente por não ter isso.
            $table->date('performed_on');
            $table->timestamp('started_at')->nullable();
            // Nulo = sessão em andamento (retomável).
            $table->timestamp('finished_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['member_id', 'performed_on']);
            $table->index(['member_id', 'finished_at']);
        });

        Schema::create('gym_set_logs', function (Blueprint $table) {
            $this->owner($table);
            $table->foreignUuid('session_id')->constrained('gym_sessions')->cascadeOnDelete();
            $table->foreignUuid('exercise_id')->constrained('gym_exercises')->cascadeOnDelete();
            $table->unsignedSmallInteger('set_number');

            $table->unsignedSmallInteger('reps')->nullable();
            $table->unsignedSmallInteger('duration_seconds')->nullable();
            $table->unsignedInteger('distance_meters')->nullable();

            // Carga como digitada + modo; o total em kg só quando dá pra
            // converter (ver GymLoadMode::totalKg()). Decimal, nunca float.
            $table->decimal('load_value', 7, 2)->nullable();
            $table->string('load_mode', 20)->nullable();
            $table->decimal('load_total_kg', 7, 2)->nullable();

            $table->foreignUuid('equipment_id')->nullable()->constrained('gym_equipment')->nullOnDelete();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            // Toque duplo em "Concluir série" não pode criar série repetida —
            // a proteção é o índice, não um if (existe), que perde a corrida.
            $table->unique(['session_id', 'exercise_id', 'set_number']);
            $table->index(['member_id', 'exercise_id', 'completed_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('gym_set_logs');
        Schema::dropIfExists('gym_sessions');
        Schema::dropIfExists('gym_workout_exercises');
        Schema::dropIfExists('gym_workouts');
        Schema::dropIfExists('gym_plans');
        Schema::dropIfExists('gym_equipment');
        Schema::dropIfExists('gym_exercises');
    }

    private function owner(Blueprint $table): void
    {
        $table->uuid('id')->primary();
        $table->foreignUuid('profile_id')->constrained('financial_profiles')->cascadeOnDelete();
        $table->foreignUuid('member_id')->constrained('profile_members')->cascadeOnDelete();
    }
};
