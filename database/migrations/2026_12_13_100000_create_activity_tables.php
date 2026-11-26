<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Área Atividades: perfil, compromissos, diário, mudanças de dia na semana, ajustes do treino por limitação e programa de corrida
 * (tudo só do dono, escopo PersonalHealthScope), mais a limitação física, que mora no acompanhamento e tem a visibilidade da Agenda de Saúde.
 *
 * Toda tabela leva `profile_id` e `member_id`: o escopo filtra a própria linha. Texto livre e o que descreve a saúde são cifrados (colunas TEXT).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('activity_profiles', function (Blueprint $table): void {
            $this->base($table);
            $table->text('goals')->nullable();
            // Dias preferidos da academia, de 0 (segunda) a 6 (domingo).
            $table->json('gym_days')->nullable();
            $table->unsignedSmallInteger('session_minutes')->default(60);
            $table->boolean('consider_limitations')->default(true);
            $table->timestamps();
            $table->unique('member_id');
        });

        Schema::create('activity_commitments', function (Blueprint $table): void {
            $this->base($table);
            $table->string('activity', 20);
            $table->text('custom_name')->nullable();
            // Fixo tem dia da semana; sem dia certo tem quantas vezes por semana.
            $table->unsignedTinyInteger('weekday')->nullable();
            $table->unsignedTinyInteger('times_per_week')->nullable();
            $table->unsignedSmallInteger('minutes');
            $table->unsignedTinyInteger('effort')->default(6);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->index(['member_id', 'is_active']);
        });

        Schema::create('activity_records', function (Blueprint $table): void {
            $this->base($table);
            $table->string('activity', 20);
            $table->text('custom_name')->nullable();
            $table->date('done_on');
            $table->string('status', 10);
            $table->unsignedSmallInteger('minutes');
            $table->unsignedInteger('distance_meters')->nullable();
            $table->unsignedTinyInteger('effort')->nullable();
            $table->unsignedTinyInteger('pain')->nullable();
            $table->unsignedSmallInteger('heart_rate')->nullable();
            $table->text('note')->nullable();
            // O item previsto da semana que este registro cumpriu (compromisso, treino ou corrida).
            $table->string('planned_key', 90)->nullable();
            $table->foreignUuid('gym_session_id')->nullable()->constrained('gym_sessions')->nullOnDelete();
            $table->timestamps();
            // Toque duplo, duas abas e a leitura que importa os treinos não podem duplicar: a proteção é o índice (regra 4).
            $table->unique(['member_id', 'planned_key']);
            $table->unique(['member_id', 'gym_session_id']);
            $table->index(['member_id', 'done_on']);
        });

        Schema::create('activity_week_moves', function (Blueprint $table): void {
            $this->base($table);
            $table->string('planned_key', 90);
            $table->date('week_start');
            $table->unsignedTinyInteger('weekday');
            $table->timestamps();
            $table->unique(['member_id', 'planned_key']);
            $table->index(['member_id', 'week_start']);
        });

        Schema::create('physical_limitations', function (Blueprint $table): void {
            $this->base($table);
            $table->foreignUuid('followup_id')->constrained('health_followups')->cascadeOnDelete();
            $table->text('region');
            $table->string('status', 20);
            $table->text('avoid')->nullable();
            $table->text('guidance')->nullable();
            $table->text('professional')->nullable();
            $table->foreignUuid('professional_id')->nullable()->constrained('health_professionals')->nullOnDelete();
            $table->date('informed_on')->nullable();
            // O que o profissional respondeu sobre correr e sobre cada esporte.
            $table->text('answers')->nullable();
            $table->foreignUuid('created_by_member_id')->nullable()->constrained('profile_members')->nullOnDelete();
            $table->foreignUuid('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUuid('changed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['member_id', 'status']);
        });

        Schema::create('gym_exercise_cautions', function (Blueprint $table): void {
            $this->base($table);
            $table->foreignUuid('limitation_id')->constrained('physical_limitations')->cascadeOnDelete();
            $table->foreignUuid('exercise_id')->constrained('gym_exercises')->cascadeOnDelete();
            $table->string('mark', 10);
            $table->timestamps();
            $table->unique(['limitation_id', 'exercise_id']);
        });

        Schema::create('gym_plan_adjustments', function (Blueprint $table): void {
            $this->base($table);
            $table->foreignUuid('plan_id')->constrained('gym_plans')->cascadeOnDelete();
            $table->foreignUuid('workout_id')->constrained('gym_workouts')->cascadeOnDelete();
            $table->foreignUuid('limitation_id')->nullable()->constrained('physical_limitations')->nullOnDelete();
            $table->string('kind', 12);
            $table->foreignUuid('exercise_id')->constrained('gym_exercises')->cascadeOnDelete();
            $table->foreignUuid('replacement_exercise_id')->nullable()->constrained('gym_exercises')->nullOnDelete();
            // Como o item do treino estava antes: é com isto que ele volta.
            $table->json('snapshot')->nullable();
            $table->foreignUuid('source_exercise_id')->nullable()->constrained('health_activity_exercises')->nullOnDelete();
            $table->timestamp('reverted_at')->nullable();
            $table->timestamps();
            $table->index(['member_id', 'plan_id', 'reverted_at']);
        });

        Schema::create('running_programs', function (Blueprint $table): void {
            $this->base($table);
            $table->boolean('is_active')->default(true);
            $table->unsignedTinyInteger('step');
            $table->unsignedTinyInteger('times_per_week');
            $table->json('weekdays');
            $table->unsignedTinyInteger('baseline_pain')->default(0);
            // Preenchido quando a pessoa escolhe começar com uma limitação que recomendava aguardar: a escolha fica registrada.
            $table->timestamp('chose_despite_limitation_at')->nullable();
            $table->date('started_on');
            $table->date('ended_on')->nullable();
            $table->timestamps();
            $table->index(['member_id', 'is_active']);
        });

        Schema::create('running_program_weeks', function (Blueprint $table): void {
            $this->base($table);
            $table->foreignUuid('program_id')->constrained('running_programs')->cascadeOnDelete();
            $table->date('week_start');
            $table->string('outcome', 10);
            $table->unsignedTinyInteger('step_before');
            $table->unsignedTinyInteger('step_after');
            $table->timestamps();
            // Uma semana se fecha uma vez só.
            $table->unique(['program_id', 'week_start']);
        });
    }

    public function down(): void
    {
        foreach (['running_program_weeks', 'running_programs', 'gym_plan_adjustments', 'gym_exercise_cautions', 'physical_limitations',
            'activity_week_moves', 'activity_records', 'activity_commitments', 'activity_profiles'] as $tabela) {
            Schema::dropIfExists($tabela);
        }
    }

    private function base(Blueprint $table): void
    {
        $table->uuid('id')->primary();
        $table->foreignUuid('profile_id')->constrained('financial_profiles')->cascadeOnDelete();
        $table->foreignUuid('member_id')->constrained('profile_members')->cascadeOnDelete();
    }
};
