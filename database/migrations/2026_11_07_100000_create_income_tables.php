<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Honorário combinado com um cliente: valor fixo cobrado a cada N meses. É o que gera os recebimentos previstos.
        Schema::create('fee_agreements', function (Blueprint $table) {
            $table->char('id', 36)->primary();
            $table->foreignUuid('professional_id')->constrained('users')->cascadeOnDelete();
            $table->char('profile_id', 36);
            $table->decimal('amount', 15, 2);
            $table->string('period', 12);
            $table->date('starts_on');
            $table->date('ends_on')->nullable();
            $table->boolean('is_active')->default(true);
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->foreign('profile_id')->references('id')->on('financial_profiles')->cascadeOnDelete();
            $table->index(['professional_id', 'is_active']);
        });

        // Livro de recebimentos do profissional. Não usa o escopo de perfil: o dado é DELE (professional_id), e nem o
        // cliente nem o outro profissional do mesmo cliente podem ver o que ele ganha.
        Schema::create('income_entries', function (Blueprint $table) {
            $table->char('id', 36)->primary();
            $table->foreignUuid('professional_id')->constrained('users')->cascadeOnDelete();
            $table->char('profile_id', 36)->nullable();
            $table->char('fee_agreement_id', 36)->nullable();
            $table->string('source', 30);
            $table->string('status', 12)->default('expected');
            $table->string('description')->nullable();
            $table->date('due_on');
            $table->unsignedSmallInteger('year');
            $table->unsignedTinyInteger('month');
            $table->decimal('amount', 15, 2);
            $table->decimal('received_amount', 15, 2)->nullable();
            $table->date('received_on')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->foreign('profile_id')->references('id')->on('financial_profiles')->nullOnDelete();
            $table->foreign('fee_agreement_id')->references('id')->on('fee_agreements')->nullOnDelete();
            $table->index(['professional_id', 'year', 'month']);
            // Idempotência da rotina que gera os previstos: o mesmo contrato não cria duas vezes o mesmo vencimento.
            $table->unique(['fee_agreement_id', 'due_on']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('income_entries');
        Schema::dropIfExists('fee_agreements');
    }
};
