<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Um objetivo pode avançar pelo saldo de MAIS DE UM investimento. A ligação deixa de ser a coluna `goals.linked_investment_id` (um só) e passa a
 * ser esta tabela de ligação. Os vínculos que já existem são copiados; a coluna antiga fica como está, sem ser lida nem escrita (um passo de
 * limpeza futuro a remove), para que voltar a versão anterior do código não perca nenhum vínculo.
 *
 * Sem model de propósito: o acesso é sempre pelo objetivo (que já respeita perfil e privacidade), e a exclusão do objetivo, do investimento
 * ou da conta leva as linhas junto pelas chaves estrangeiras.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('goal_investment', function (Blueprint $table) {
            $table->foreignUuid('goal_id')->constrained('goals')->cascadeOnDelete();
            $table->foreignUuid('investment_id')->constrained('investment_records')->cascadeOnDelete();

            // Idempotência por índice único (regra 4): a mesma ligação nunca existe duas vezes, mesmo com duas abas salvando juntas.
            $table->primary(['goal_id', 'investment_id']);
        });

        DB::table('goal_investment')->insertUsing(
            ['goal_id', 'investment_id'],
            DB::table('goals')->whereNotNull('linked_investment_id')->select('id', 'linked_investment_id'),
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('goal_investment');
    }
};
