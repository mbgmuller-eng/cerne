<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Acompanhamento do consultor financeiro com o cliente: reuniões, tarefas, demandas e notas.
 *
 * Todo registro pertence a uma relação (perfil do cliente + consultor). O histórico fica guardado mesmo quando o vínculo
 * acaba: se o MESMO consultor voltar a ser vinculado, tudo reaparece; um consultor novo começa do zero, porque só enxerga o
 * que tem o próprio consultant_id.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('engagement_meetings', function (Blueprint $table): void {
            $table->char('id', 36)->primary();
            $table->char('profile_id', 36);
            $table->char('consultant_id', 36);
            $table->dateTime('scheduled_at');
            $table->unsignedSmallInteger('duration_minutes')->default(45);
            $table->string('format', 20)->default('video');
            $table->string('location', 255)->nullable();
            $table->text('agenda')->nullable();
            $table->text('prep_notes')->nullable();
            $table->string('status', 20)->default('scheduled');
            $table->text('summary')->nullable();
            $table->char('created_by_user_id', 36)->nullable();
            $table->timestamps();

            $table->foreign('profile_id')->references('id')->on('financial_profiles')->cascadeOnDelete();
            $table->foreign('consultant_id')->references('id')->on('users')->cascadeOnDelete();
            $table->index(['profile_id', 'consultant_id', 'scheduled_at'], 'eng_meetings_relation_idx');
            $table->index(['consultant_id', 'scheduled_at'], 'eng_meetings_consultant_idx');
        });

        Schema::create('engagement_tasks', function (Blueprint $table): void {
            $table->char('id', 36)->primary();
            $table->char('profile_id', 36);
            $table->char('consultant_id', 36);
            $table->char('meeting_id', 36)->nullable();
            $table->string('title', 160);
            $table->text('description')->nullable();
            $table->string('owner', 12);
            $table->date('due_date')->nullable();
            $table->string('status', 12)->default('todo');
            $table->dateTime('completed_at')->nullable();
            $table->char('created_by_user_id', 36)->nullable();
            $table->timestamps();

            $table->foreign('profile_id')->references('id')->on('financial_profiles')->cascadeOnDelete();
            $table->foreign('consultant_id')->references('id')->on('users')->cascadeOnDelete();
            $table->foreign('meeting_id')->references('id')->on('engagement_meetings')->nullOnDelete();
            $table->index(['profile_id', 'consultant_id', 'status'], 'eng_tasks_relation_idx');
            $table->index(['consultant_id', 'due_date'], 'eng_tasks_consultant_idx');
        });

        Schema::create('engagement_requests', function (Blueprint $table): void {
            $table->char('id', 36)->primary();
            $table->char('profile_id', 36);
            $table->char('consultant_id', 36);
            $table->char('task_id', 36)->nullable();
            $table->string('title', 160);
            $table->text('body');
            $table->string('status', 12)->default('open');
            $table->char('created_by_user_id', 36)->nullable();
            $table->dateTime('resolved_at')->nullable();
            $table->timestamps();

            $table->foreign('profile_id')->references('id')->on('financial_profiles')->cascadeOnDelete();
            $table->foreign('consultant_id')->references('id')->on('users')->cascadeOnDelete();
            $table->foreign('task_id')->references('id')->on('engagement_tasks')->nullOnDelete();
            $table->index(['profile_id', 'consultant_id', 'status'], 'eng_requests_relation_idx');
            $table->index(['consultant_id', 'status'], 'eng_requests_consultant_idx');
        });

        Schema::create('engagement_request_comments', function (Blueprint $table): void {
            $table->char('id', 36)->primary();
            $table->char('profile_id', 36);
            $table->char('consultant_id', 36);
            $table->char('request_id', 36);
            $table->char('author_user_id', 36)->nullable();
            $table->text('body');
            $table->timestamps();

            $table->foreign('profile_id')->references('id')->on('financial_profiles')->cascadeOnDelete();
            $table->foreign('consultant_id')->references('id')->on('users')->cascadeOnDelete();
            $table->foreign('request_id')->references('id')->on('engagement_requests')->cascadeOnDelete();
            $table->index('request_id', 'eng_comments_request_idx');
        });

        Schema::create('engagement_notes', function (Blueprint $table): void {
            $table->char('id', 36)->primary();
            $table->char('profile_id', 36);
            $table->char('consultant_id', 36);
            $table->char('author_user_id', 36)->nullable();
            $table->text('body');
            $table->boolean('is_private')->default(false);
            $table->timestamps();

            $table->foreign('profile_id')->references('id')->on('financial_profiles')->cascadeOnDelete();
            $table->foreign('consultant_id')->references('id')->on('users')->cascadeOnDelete();
            $table->index(['profile_id', 'consultant_id', 'is_private'], 'eng_notes_relation_idx');
        });

        // Lembrete já enviado: o índice único é o que impede aviso em dobro se o agendador disparar duas vezes (regra 4).
        Schema::create('engagement_reminders', function (Blueprint $table): void {
            $table->char('id', 36)->primary();
            $table->char('user_id', 36);
            $table->string('subject_type', 20);
            $table->char('subject_id', 36);
            $table->string('kind', 24);
            $table->date('sent_on');
            $table->timestamps();

            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->unique(['user_id', 'subject_type', 'subject_id', 'kind'], 'eng_reminders_unique');
        });

        // O que o consultor decidiu ao encerrar o vínculo: enviar o plano ao e-mail do cliente ou não.
        Schema::table('consultant_clients', function (Blueprint $table): void {
            $table->dateTime('ended_at')->nullable()->after('accepted_at');
            $table->string('plan_delivery', 12)->nullable()->after('ended_at');
        });
    }

    public function down(): void
    {
        Schema::table('consultant_clients', function (Blueprint $table): void {
            $table->dropColumn(['ended_at', 'plan_delivery']);
        });

        foreach (['engagement_reminders', 'engagement_notes', 'engagement_request_comments', 'engagement_requests', 'engagement_tasks', 'engagement_meetings'] as $tabela) {
            Schema::dropIfExists($tabela);
        }
    }
};
