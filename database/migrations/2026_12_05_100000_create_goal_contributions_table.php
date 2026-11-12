<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Aportes (e retiradas) de um objetivo, mês a mês. Para o objetivo SEM ligação a investimento ou consórcio, o que ele tem é o saldo inicial
        // (goals.current_amount) mais a soma disto: duas fontes separadas, nenhuma número à mão para divergir da outra.
        Schema::create('goal_contributions', function (Blueprint $table): void {
            $table->char('id', 36)->primary();
            $table->char('profile_id', 36);
            $table->char('goal_id', 36);
            $table->char('member_id', 36)->nullable();
            $table->boolean('is_private')->default(false);
            // Positivo = aporte; negativo = retirada.
            $table->decimal('amount', 15, 2);
            $table->date('contributed_on');
            // A observação é dado pessoal: cifrada (Sensitive), por isso TEXT.
            $table->text('note')->nullable();
            $table->char('created_by_user_id', 36)->nullable();
            $table->timestamps();

            $table->foreign('profile_id')->references('id')->on('financial_profiles')->cascadeOnDelete();
            $table->foreign('goal_id')->references('id')->on('goals')->cascadeOnDelete();
            $table->index(['goal_id', 'contributed_on'], 'goal_contributions_goal_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('goal_contributions');
    }
};
