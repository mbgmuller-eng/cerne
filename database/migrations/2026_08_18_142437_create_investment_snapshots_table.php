<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Foto mensal da posição. É o que permite montar a curva de evolução
     * do patrimônio sem depender do histórico de transações.
     */
    public function up(): void
    {
        Schema::create('investment_snapshots', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('profile_id')->constrained('financial_profiles')->cascadeOnDelete();
            $table->foreignUuid('investment_id')->constrained('investment_records')->cascadeOnDelete();

            $table->unsignedSmallInteger('year');
            $table->unsignedTinyInteger('month');
            $table->decimal('amount', 15, 2);
            $table->decimal('quantity', 15, 6)->nullable();
            $table->timestamp('created_at')->useCurrent();

            // Uma foto por mês por ativo — torna o job mensal idempotente.
            $table->unique(['investment_id', 'year', 'month']);
            $table->index(['profile_id', 'year', 'month']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('investment_snapshots');
    }
};
