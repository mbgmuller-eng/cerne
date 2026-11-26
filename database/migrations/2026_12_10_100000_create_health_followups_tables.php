<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cerne Saúde: rotinas preventivas (check-up), acompanhamento de um problema e programa de atividades.
 *
 * Mesmo escopo da Agenda (CoupleHealthScope): o casal e quem acompanha a saúde da pessoa veem; consultor e corretor, nunca. Toda tabela leva
 * `member_id` (de quem é o dado): é por ele que o cuidador de um não vê o outro. Texto livre é cifrado (colunas TEXT).
 *
 * Exame pedido e ainda sem data fica em `health_exam_requests`, e não na Agenda: a consulta da Agenda sempre tem data, e muita coisa depende disso
 * (calendário, lembrete, "quem leva"). Ao marcar a data, o pedido vira um compromisso e fica ligado a ele.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('health_routines', function (Blueprint $table): void {
            $this->base($table);
            $table->text('name');
            $table->unsignedSmallInteger('interval_months');
            // A última vez informada à mão (o que aconteceu antes do Cerne); a próxima sai dela ou da última consulta realizada ligada.
            $table->date('last_done_on')->nullable();
            $table->date('next_due_on')->nullable();
            $table->boolean('is_active')->default(true);
            $this->actors($table);
            $table->index(['is_active', 'next_due_on']);
        });

        Schema::create('health_followups', function (Blueprint $table): void {
            $this->base($table);
            $table->text('title');
            $table->string('status', 20);
            $table->date('started_on');
            $table->date('resolved_on')->nullable();
            // O programa: quem orientou, quando e o que evitar.
            $table->text('guided_by')->nullable();
            $table->date('guided_on')->nullable();
            $table->text('avoid')->nullable();
            $this->actors($table);
        });

        Schema::create('health_followup_appointment', function (Blueprint $table): void {
            $table->foreignUuid('followup_id')->constrained('health_followups')->cascadeOnDelete();
            $table->foreignUuid('appointment_id')->constrained('health_appointments')->cascadeOnDelete();
            $table->primary(['followup_id', 'appointment_id']);
        });

        Schema::create('health_followup_notes', function (Blueprint $table): void {
            $this->base($table);
            $table->foreignUuid('followup_id')->constrained('health_followups')->cascadeOnDelete();
            $table->date('noted_on');
            $table->text('text')->nullable();
            $table->unsignedTinyInteger('pain')->nullable();
            $this->actors($table);
        });

        Schema::create('health_exam_requests', function (Blueprint $table): void {
            $this->base($table);
            $table->string('kind', 20);
            $table->text('title');
            $table->date('requested_on')->nullable();
            $table->text('requested_by')->nullable();
            $table->foreignUuid('routine_id')->nullable()->constrained('health_routines')->nullOnDelete();
            $table->foreignUuid('followup_id')->nullable()->constrained('health_followups')->nullOnDelete();
            // Preenchido ao marcar a data. Apagar o compromisso devolve o pedido para "falta agendar".
            $table->foreignUuid('appointment_id')->nullable()->unique()->constrained('health_appointments')->nullOnDelete();
            $this->actors($table);
        });

        Schema::table('health_appointments', function (Blueprint $table): void {
            $table->foreignUuid('routine_id')->nullable()->after('member_id')->constrained('health_routines')->nullOnDelete();
        });

        Schema::table('documents', function (Blueprint $table): void {
            $table->foreignUuid('health_appointment_id')->nullable()->after('insurance_policy_id')->constrained('health_appointments')->nullOnDelete();
            $table->foreignUuid('health_followup_id')->nullable()->after('health_appointment_id')->constrained('health_followups')->nullOnDelete();
        });

        Schema::create('health_activities', function (Blueprint $table): void {
            $this->base($table);
            $table->foreignUuid('followup_id')->constrained('health_followups')->cascadeOnDelete();
            $table->string('kind', 20);
            $table->text('name');
            $table->unsignedTinyInteger('per_week');
            $table->unsignedSmallInteger('total_sessions')->nullable();
            // Só a própria pessoa liga uma fase da Academia (os treinos continuam só dela; aqui chegam a presença e a nota de dor).
            $table->foreignUuid('gym_plan_id')->nullable()->constrained('gym_plans')->nullOnDelete();
            $table->boolean('is_active')->default(true);
            $this->actors($table);
        });

        Schema::create('health_activity_exercises', function (Blueprint $table): void {
            $this->base($table);
            $table->foreignUuid('activity_id')->constrained('health_activities')->cascadeOnDelete();
            $table->text('name');
            $table->text('dose')->nullable();
            $table->text('note')->nullable();
            $table->unsignedSmallInteger('position')->default(0);
            $this->actors($table);
        });

        Schema::create('health_activity_sessions', function (Blueprint $table): void {
            $this->base($table);
            $table->foreignUuid('activity_id')->constrained('health_activities')->cascadeOnDelete();
            $table->date('done_on');
            $table->boolean('attended')->default(true);
            $table->unsignedTinyInteger('pain')->nullable();
            $table->text('note')->nullable();
            $table->foreignUuid('gym_session_id')->nullable()->constrained('gym_sessions')->nullOnDelete();
            $this->actors($table);
            // Um treino da Academia vira no máximo UMA presença por atividade (regra 4: a proteção é do banco).
            $table->unique(['activity_id', 'gym_session_id'], 'health_activity_sessions_gym_unique');
            $table->index(['activity_id', 'done_on']);
        });
    }

    private function base(Blueprint $table): void
    {
        $table->uuid('id')->primary();
        $table->foreignUuid('profile_id')->constrained('financial_profiles')->cascadeOnDelete();
        $table->foreignUuid('member_id')->constrained('profile_members')->cascadeOnDelete();
    }

    private function actors(Blueprint $table): void
    {
        $table->foreignUuid('created_by_member_id')->nullable()->constrained('profile_members')->nullOnDelete();
        $table->foreignUuid('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
        $table->foreignUuid('changed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
        $table->timestamps();
    }

    public function down(): void
    {
        Schema::dropIfExists('health_activity_sessions');
        Schema::dropIfExists('health_activity_exercises');
        Schema::dropIfExists('health_activities');

        Schema::table('documents', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('health_followup_id');
            $table->dropConstrainedForeignId('health_appointment_id');
        });
        Schema::table('health_appointments', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('routine_id');
        });

        Schema::dropIfExists('health_exam_requests');
        Schema::dropIfExists('health_followup_notes');
        Schema::dropIfExists('health_followup_appointment');
        Schema::dropIfExists('health_followups');
        Schema::dropIfExists('health_routines');
    }
};
