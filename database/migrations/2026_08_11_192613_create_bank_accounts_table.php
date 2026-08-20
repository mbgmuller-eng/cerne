<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A especificação lista `is_joint` E `is_conjunta` nesta tabela — dois
     * campos para a mesma ideia, em idiomas diferentes. Aqui fica só um
     * conjunto, em inglês, coerente com o resto do schema:
     *
     *   is_joint                 — conta do casal
     *   visible_to_partner       — o cônjuge enxerga
     *   included_in_consolidated — entra no patrimônio consolidado
     *
     * Regras (aplicadas no model): conta conjunta força as duas outras em
     * true; entrar no consolidado exige ser visível ao cônjuge.
     */
    public function up(): void
    {
        Schema::create('bank_accounts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('profile_id')->constrained('financial_profiles')->cascadeOnDelete();
            $table->foreignUuid('member_id')->constrained('profile_members')->cascadeOnDelete();

            $table->string('bank_name', 100);
            $table->string('account_type', 30);
            $table->string('agency', 20)->nullable();
            $table->string('account_number', 30)->nullable();
            $table->decimal('current_balance', 15, 2)->default(0);

            $table->boolean('is_joint')->default(false);
            $table->boolean('visible_to_partner')->default(true);
            $table->boolean('included_in_consolidated')->default(true);

            $table->char('color_hex', 7)->nullable();
            $table->boolean('is_active')->default(true);
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['profile_id', 'is_active']);
            $table->index(['profile_id', 'member_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bank_accounts');
    }
};
