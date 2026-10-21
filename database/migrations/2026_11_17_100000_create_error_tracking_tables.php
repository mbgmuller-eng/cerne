<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Registro dos erros da plataforma, agrupados por "impressão digital" (classe + arquivo + linha): o mesmo erro mil vezes é UMA linha com
 * contador, não mil linhas. Nada daqui guarda dado do cliente: a mensagem é limpa de e-mail, CPF e números longos, a rota é o padrão
 * (`/convite/{token}`, nunca o endereço real) e o usuário é só o id.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('error_logs', function (Blueprint $table) {
            $table->id();
            $table->char('fingerprint', 40)->unique();
            $table->string('exception_class', 255);
            $table->text('message');
            $table->string('file', 500);
            $table->unsignedInteger('line')->default(0);
            $table->text('trace')->nullable();
            // Rota (nome ou padrão do endereço) ou comando artisan: de onde veio.
            $table->string('origin', 255)->nullable();
            $table->string('http_method', 10)->nullable();
            $table->unsignedSmallInteger('status_code')->nullable();
            $table->string('context', 10)->default('web');
            $table->unsignedInteger('occurrences')->default(1);
            $table->dateTime('first_seen_at');
            $table->dateTime('last_seen_at');
            // new (aberto), resolved ou ignored. Erro resolvido que volta reabre sozinho (regressions conta quantas vezes).
            $table->string('status', 10)->default('new');
            $table->dateTime('resolved_at')->nullable();
            $table->dateTime('reopened_at')->nullable();
            $table->unsignedSmallInteger('regressions')->default(0);
            $table->timestamps();

            $table->index(['status', 'last_seen_at']);
        });

        // Quantas vezes por dia: é o que o relatório diário e a tela somam.
        Schema::create('error_log_days', function (Blueprint $table) {
            $table->unsignedBigInteger('error_log_id');
            $table->date('day');
            $table->unsignedInteger('count')->default(1);

            $table->primary(['error_log_id', 'day']);
            $table->foreign('error_log_id')->references('id')->on('error_logs')->cascadeOnDelete();
        });

        // Quem foi afetado (só o id do usuário, uma vez por erro): quantos clientes bateram nesse erro.
        Schema::create('error_log_users', function (Blueprint $table) {
            $table->unsignedBigInteger('error_log_id');
            $table->char('user_id', 36);

            $table->primary(['error_log_id', 'user_id']);
            $table->foreign('error_log_id')->references('id')->on('error_logs')->cascadeOnDelete();
        });

        // Um relatório por dia: o índice único é o que impede o e-mail de sair duas vezes se o cron disparar repetido.
        Schema::create('error_report_digests', function (Blueprint $table) {
            $table->id();
            $table->date('sent_on')->unique();
            $table->unsignedInteger('occurrences')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('error_report_digests');
        Schema::dropIfExists('error_log_users');
        Schema::dropIfExists('error_log_days');
        Schema::dropIfExists('error_logs');
    }
};
