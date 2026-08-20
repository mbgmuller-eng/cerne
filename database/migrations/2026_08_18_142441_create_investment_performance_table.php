<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('investment_performance', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('profile_id')->constrained('financial_profiles')->cascadeOnDelete();
            $table->foreignUuid('member_id')->constrained('profile_members')->cascadeOnDelete();

            // Nulo = rentabilidade da carteira inteira, não de um ativo.
            $table->foreignUuid('investment_id')->nullable()->constrained('investment_records')->cascadeOnDelete();

            $table->string('period_type', 20);
            $table->unsignedSmallInteger('year');
            $table->unsignedTinyInteger('month')->nullable();

            $table->decimal('return_amount', 15, 2);
            $table->decimal('return_percentage', 8, 4);

            $table->string('benchmark', 20)->nullable();
            $table->decimal('benchmark_return', 8, 4)->nullable();
            $table->decimal('vs_benchmark', 8, 4)->nullable();

            $table->string('institution', 255)->nullable();
            $table->uuid('source_document_id')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['investment_id', 'period_type', 'year', 'month'], 'perf_unique_period');
            $table->index(['profile_id', 'year', 'month']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('investment_performance');
    }
};
