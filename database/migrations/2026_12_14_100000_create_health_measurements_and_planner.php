<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ficha de Saúde ampliada (medidas, problemas com situação e origem, limitação do passado), prontidão e plano da semana, e a revisão das regras
 * por um profissional de educação física.
 *
 * Peso, altura e cintura são cifrados: começam visíveis só para a própria pessoa (HealthMeasurementScope). A revisão das regras não guarda dado
 * de ninguém que use o Cerne: só o profissional convidado e o parecer dele sobre as regras.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('health_measurements', function (Blueprint $table): void {
            $this->base($table);
            $table->date('measured_on');
            $table->text('weight_kg')->nullable();
            $table->text('height_cm')->nullable();
            $table->text('waist_cm')->nullable();
            $table->foreignUuid('created_by_member_id')->nullable()->constrained('profile_members')->nullOnDelete();
            $table->foreignUuid('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUuid('changed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->index(['member_id', 'measured_on']);
        });

        Schema::table('health_cards', function (Blueprint $table): void {
            // Tudo desligado por padrão: quem decide mostrar é a própria pessoa.
            $table->boolean('share_measurements')->default(false);
            $table->boolean('qr_measurements')->default(false);
            $table->boolean('qr_limitations')->default(false);
        });

        Schema::table('health_conditions', function (Blueprint $table): void {
            $table->string('status', 20)->default('active');
            $table->unsignedSmallInteger('since_year')->nullable();
            // Quem marca é a pessoa: o Cerne não deduz pelo nome do problema.
            $table->boolean('exercise_care')->default(false);
            $table->foreignUuid('followup_id')->nullable()->constrained('health_followups')->nullOnDelete();
            $table->foreignUuid('appointment_id')->nullable()->constrained('health_appointments')->nullOnDelete();
            $table->foreignUuid('exam_report_id')->nullable()->constrained('health_exam_reports')->nullOnDelete();
        });

        // A limitação pode ser do passado, sem acompanhamento. E excluir o acompanhamento não apaga o que é um fato sobre a pessoa.
        Schema::table('physical_limitations', function (Blueprint $table): void {
            $table->dropForeign(['followup_id']);
        });
        Schema::table('physical_limitations', function (Blueprint $table): void {
            $table->uuid('followup_id')->nullable()->change();
            $table->foreign('followup_id')->references('id')->on('health_followups')->nullOnDelete();
            $table->unsignedSmallInteger('since_year')->nullable();
            $table->foreignUuid('appointment_id')->nullable()->constrained('health_appointments')->nullOnDelete();
            $table->foreignUuid('exam_report_id')->nullable()->constrained('health_exam_reports')->nullOnDelete();
        });

        Schema::table('activity_profiles', function (Blueprint $table): void {
            $table->text('weight_goal')->nullable();
            $table->text('weight_goal_start')->nullable();
            $table->date('weight_goal_set_on')->nullable();
        });

        Schema::create('activity_readiness', function (Blueprint $table): void {
            $this->base($table);
            $table->text('answers');
            // Só quando há algum "sim": 'yes' (tem liberação) ou 'no' (ainda não tem).
            $table->string('clearance', 8)->nullable();
            $table->text('cleared_by')->nullable();
            $table->date('cleared_on')->nullable();
            $table->date('answered_on');
            $table->timestamps();
            $table->unique('member_id');
        });

        Schema::create('activity_plans', function (Blueprint $table): void {
            $this->base($table);
            $table->boolean('is_active')->default(true);
            $table->string('goal', 20);
            $table->json('strength_days');
            $table->json('aerobic_days');
            $table->json('aerobic_types');
            $table->unsignedSmallInteger('sport_minutes');
            $table->unsignedSmallInteger('base_minutes');
            $table->unsignedSmallInteger('target_minutes');
            $table->boolean('is_light')->default(false);
            // Como estava antes, para a atualização poder ser desfeita.
            $table->json('previous_gym_days')->nullable();
            $table->foreignUuid('gym_plan_id')->nullable()->constrained('gym_plans')->nullOnDelete();
            $table->string('previous_gym_goal', 20)->nullable();
            $table->date('started_on');
            $table->date('ended_on')->nullable();
            $table->timestamps();
            $table->index(['member_id', 'is_active']);
        });

        Schema::table('gym_plan_adjustments', function (Blueprint $table): void {
            $table->foreignUuid('activity_plan_id')->nullable()->constrained('activity_plans')->nullOnDelete();
        });

        Schema::create('rule_reviewers', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('name', 160);
            $table->string('registry', 40);
            $table->string('email', 190)->nullable();
            // Só o hash do link: quem lê o banco não consegue entrar como o revisor.
            $table->char('token_hash', 64)->unique();
            $table->foreignUuid('invited_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('expires_at');
            $table->timestamp('revoked_at')->nullable();
            $table->timestamp('last_access_at')->nullable();
            $table->timestamps();
        });

        Schema::create('rule_reviews', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('reviewer_id')->constrained('rule_reviewers')->cascadeOnDelete();
            $table->string('rule_key', 40);
            // A impressão digital do conteúdo revisado: mudou a regra, a aprovação deixa de valer.
            $table->char('rule_version', 40);
            $table->string('decision', 10)->nullable();
            $table->text('comment')->nullable();
            $table->json('wrong_items')->nullable();
            $table->text('missing')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamps();
            $table->unique(['reviewer_id', 'rule_key', 'rule_version']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('rule_reviews');
        Schema::dropIfExists('rule_reviewers');
        Schema::table('gym_plan_adjustments', fn (Blueprint $t) => $t->dropConstrainedForeignId('activity_plan_id'));
        Schema::dropIfExists('activity_plans');
        Schema::dropIfExists('activity_readiness');
        Schema::table('activity_profiles', fn (Blueprint $t) => $t->dropColumn(['weight_goal', 'weight_goal_start', 'weight_goal_set_on']));
        Schema::table('physical_limitations', function (Blueprint $t): void {
            $t->dropConstrainedForeignId('appointment_id');
            $t->dropConstrainedForeignId('exam_report_id');
            $t->dropColumn('since_year');
        });
        Schema::table('health_conditions', function (Blueprint $t): void {
            $t->dropConstrainedForeignId('followup_id');
            $t->dropConstrainedForeignId('appointment_id');
            $t->dropConstrainedForeignId('exam_report_id');
            $t->dropColumn(['status', 'since_year', 'exercise_care']);
        });
        Schema::table('health_cards', fn (Blueprint $t) => $t->dropColumn(['share_measurements', 'qr_measurements', 'qr_limitations']));
        Schema::dropIfExists('health_measurements');
    }

    private function base(Blueprint $table): void
    {
        $table->uuid('id')->primary();
        $table->foreignUuid('profile_id')->constrained('financial_profiles')->cascadeOnDelete();
        $table->foreignUuid('member_id')->constrained('profile_members')->cascadeOnDelete();
    }
};
