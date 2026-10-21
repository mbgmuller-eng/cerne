<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Estrutura de privacidade (LGPD): registro de quem entra na conta de outra pessoa, pedidos de exportação de dados, registro das exclusões de
 * conta e registro de incidentes de segurança.
 */
return new class extends Migration
{
    public function up(): void
    {
        // "Entrar como": quem, em qual conta, quando e POR QUÊ. Sem chave estrangeira para a conta acessada: o registro tem que sobreviver à
        // exclusão dela (nome e e-mail viram "[excluído]" nessa hora, o id e o papel ficam).
        Schema::create('impersonation_logs', function (Blueprint $table) {
            $table->id();
            $table->char('admin_id', 36);
            $table->string('admin_email', 255);
            $table->char('target_user_id', 36);
            $table->string('target_name', 255)->nullable();
            $table->string('target_email', 255)->nullable();
            $table->string('target_role', 20)->nullable();
            $table->text('reason');
            $table->dateTime('started_at');
            $table->dateTime('ended_at')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 255)->nullable();
            $table->timestamps();

            $table->index('started_at');
            $table->index('target_user_id');
        });

        // Pedido de "baixar meus dados". O arquivo (zip) fica em disco privado por poucos dias.
        Schema::create('data_exports', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('status', 10)->default('pending');
            $table->string('path', 500)->nullable();
            $table->unsignedBigInteger('size_bytes')->nullable();
            $table->dateTime('ready_at')->nullable();
            $table->dateTime('expires_at')->nullable();
            $table->dateTime('downloaded_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'created_at']);
            $table->index('expires_at');
        });

        // Prestação de contas: que contas foram excluídas, quando e por quem. Não guarda nome nem e-mail de ninguém.
        Schema::create('account_deletions', function (Blueprint $table) {
            $table->id();
            $table->string('role', 20);
            $table->boolean('had_profile')->default(false);
            $table->string('requested_by', 10);
            $table->dateTime('requested_at')->nullable();
            $table->dateTime('deleted_at');
            $table->unsignedInteger('files_removed')->default(0);
            $table->timestamps();
        });

        // Registro de incidentes de segurança com dado pessoal (a ANPD pede esse registro e o prazo de comunicação).
        Schema::create('security_incidents', function (Blueprint $table) {
            $table->id();
            $table->string('title', 160);
            $table->text('description');
            $table->dateTime('occurred_at')->nullable();
            $table->dateTime('detected_at');
            $table->text('data_affected')->nullable();
            $table->unsignedInteger('people_affected')->nullable();
            $table->string('risk', 10)->default('unknown');
            $table->text('measures')->nullable();
            $table->dateTime('anpd_notified_at')->nullable();
            $table->dateTime('people_notified_at')->nullable();
            $table->string('status', 12)->default('open');
            $table->char('registered_by', 36)->nullable();
            $table->timestamps();

            $table->index(['status', 'detected_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('security_incidents');
        Schema::dropIfExists('account_deletions');
        Schema::dropIfExists('data_exports');
        Schema::dropIfExists('impersonation_logs');
    }
};
