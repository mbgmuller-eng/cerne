<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Casal com "vida financeira" onde um esconde gasto do outro (gasto
 * essencial com member_id preenchido, visibilidade own_only) precisa de
 * uma reserva de paz/oportunidade que não pertence a nenhum dos dois —
 * a fatia visível aos dois, baseada só no que é família (member_id
 * nulo em expense_records). member_id vira opcional aqui pelo mesmo
 * motivo de expense_records/income_records: nulo = da família, não de
 * uma pessoa.
 *
 * MySQL trata cada NULL como distinto num índice único — sem tratamento,
 * duas linhas "reserva de paz da família" caberiam ao mesmo tempo no
 * mesmo perfil. `member_key` é a coluna que resolve isso: sentinela no
 * lugar do nulo, mantida pelo próprio model (FinancialReserve, evento
 * saving) — NÃO é coluna gerada pelo MySQL. Testado e confirmado: MySQL
 * 8.4 recusa `GENERATED ALWAYS AS` referenciando qualquer coluna que
 * participe de uma foreign key (aqui, member_id e linked_investment_id
 * — erro 1215 nos dois), então a alternativa é a aplicação preencher a
 * coluna normal no save.
 *
 * O índice único antigo (profile_id, member_id, reserve_type) hoje
 * sustenta DUAS foreign keys ao mesmo tempo: profile_id (por ser a
 * coluna mais à esquerda) e member_id (única coluna que aparece nele).
 * Antes de derrubá-lo, as duas precisam de suporte substituto.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('financial_reserves', function (Blueprint $table) {
            $table->index('member_id', 'financial_reserves_member_id_fk_index');
            $table->char('member_key', 36)->nullable()->after('member_id');
        });

        DB::statement("UPDATE financial_reserves SET member_key = COALESCE(member_id, '00000000-0000-0000-0000-000000000000')");

        // Sem doctrine/dbal instalado, ->change() não está disponível —
        // ALTER MODIFY direto, mesmo padrão já usado noutras migrations.
        DB::statement("ALTER TABLE financial_reserves MODIFY member_key CHAR(36) NOT NULL DEFAULT '00000000-0000-0000-0000-000000000000'");

        Schema::table('financial_reserves', function (Blueprint $table) {
            $table->unique(['profile_id', 'member_key', 'reserve_type']);
        });

        Schema::table('financial_reserves', function (Blueprint $table) {
            $table->dropUnique(['profile_id', 'member_id', 'reserve_type']);
        });

        DB::statement('ALTER TABLE financial_reserves MODIFY member_id CHAR(36) NULL');
    }

    public function down(): void
    {
        Schema::table('financial_reserves', function (Blueprint $table) {
            $table->dropUnique(['profile_id', 'member_key', 'reserve_type']);
            $table->dropColumn('member_key');
        });

        DB::statement('DELETE FROM financial_reserves WHERE member_id IS NULL');
        DB::statement('ALTER TABLE financial_reserves MODIFY member_id CHAR(36) NOT NULL');

        Schema::table('financial_reserves', function (Blueprint $table) {
            $table->unique(['profile_id', 'member_id', 'reserve_type']);
            $table->dropIndex('financial_reserves_member_id_fk_index');
        });
    }
};
