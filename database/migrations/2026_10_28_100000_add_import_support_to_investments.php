<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Carga de investimentos vindos da corretora (notas de corretagem, extrato de preço médio e relatório de performance).
     *
     *  - investment_transactions.import_key: chave estável de cada movimentação importada (nº da nota, ou dia + tipo + ordem
     *    no extrato de preço médio). O índice único (ativo + chave) é o que deixa a carga rodar de novo sem duplicar nada.
     *  - investment_portfolio_months: a carteira de uma corretora mês a mês (patrimônio, movimentações, impostos, ganho,
     *    rentabilidade e CDI do mês). É a base dos gráficos de evolução do patrimônio e de rentabilidade contra o CDI.
     */
    public function up(): void
    {
        Schema::table('investment_transactions', function (Blueprint $table) {
            $table->string('import_key', 120)->nullable()->after('source_document_id');
            $table->unique(['investment_id', 'import_key'], 'investment_tx_import_key_unique');
        });

        Schema::create('investment_portfolio_months', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('profile_id')->constrained('financial_profiles')->cascadeOnDelete();
            $table->foreignUuid('member_id')->nullable()->constrained('profile_members')->cascadeOnDelete();

            // Corretora ou conta de onde vem a série; vazio nunca é nulo, para o índice único valer.
            $table->string('institution', 120)->default('');
            $table->unsignedSmallInteger('year');
            $table->unsignedTinyInteger('month');

            // Os saldos só existem nos últimos 12 meses do relatório; antes disso só há as rentabilidades.
            $table->decimal('opening_balance', 15, 2)->nullable();
            $table->decimal('movements', 15, 2)->nullable();
            $table->decimal('taxes', 15, 2)->nullable();
            $table->decimal('closing_balance', 15, 2)->nullable();
            $table->decimal('gain', 15, 2)->nullable();
            $table->decimal('return_percent', 10, 4)->nullable();
            $table->decimal('cdi_percent', 10, 4)->nullable();

            $table->boolean('is_private')->default(false);
            $table->uuid('source_document_id')->nullable();
            $table->timestamps();

            $table->unique(['profile_id', 'member_id', 'institution', 'year', 'month'], 'portfolio_months_unique');
            $table->index(['profile_id', 'year', 'month']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('investment_portfolio_months');

        Schema::table('investment_transactions', function (Blueprint $table) {
            $table->dropUnique('investment_tx_import_key_unique');
            $table->dropColumn('import_key');
        });
    }
};
