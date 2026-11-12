<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Simulação de acordo guardada: as propostas digitadas e o saldo da dívida NA DATA, para comparar com uma proposta nova no futuro.
        // O resultado não é guardado (sai da mesma conta, com o saldo da época). As propostas ficam cifradas (SensitiveArray): valores de
        // negociação de dívida são dado financeiro pessoal.
        Schema::create('debt_simulations', function (Blueprint $table): void {
            $table->char('id', 36)->primary();
            $table->char('profile_id', 36);
            $table->char('debt_id', 36);
            $table->char('member_id', 36)->nullable();
            $table->boolean('is_private')->default(false);
            $table->string('name', 80);
            $table->decimal('balance', 15, 2);
            $table->string('growth_percent', 8)->nullable();
            $table->longText('proposals');
            $table->char('created_by_user_id', 36)->nullable();
            $table->timestamps();

            $table->foreign('profile_id')->references('id')->on('financial_profiles')->cascadeOnDelete();
            $table->foreign('debt_id')->references('id')->on('debts')->cascadeOnDelete();
            $table->index(['debt_id', 'created_at'], 'debt_simulations_debt_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('debt_simulations');
    }
};
