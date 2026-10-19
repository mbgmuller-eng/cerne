<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Regras de investimento reaproveitam commission_rules (kind = 'investment'): o percentual é ao ano.
        Schema::table('commission_rules', function (Blueprint $table) {
            $table->decimal('annual_percent', 7, 4)->nullable()->after('renewal_percent');
        });

        // Percentual cobrado do cliente sobre o patrimônio dele, a cada mês, trimestre, semestre ou ano.
        Schema::create('asset_fee_agreements', function (Blueprint $table) {
            $table->char('id', 36)->primary();
            $table->foreignUuid('professional_id')->constrained('users')->cascadeOnDelete();
            $table->char('profile_id', 36);
            $table->string('period', 12);
            // total: um percentual sobre todo o patrimônio. rules: cada produto paga o percentual da regra dele, e o que não tem regra fica fora.
            $table->string('mode', 10)->default('total');
            $table->decimal('total_percent', 7, 4)->nullable();
            $table->date('starts_on');
            $table->date('ends_on')->nullable();
            $table->boolean('is_active')->default(true);
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->foreign('profile_id')->references('id')->on('financial_profiles')->cascadeOnDelete();
            $table->index(['professional_id', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('asset_fee_agreements');
        Schema::table('commission_rules', function (Blueprint $table) {
            $table->dropColumn('annual_percent');
        });
    }
};
