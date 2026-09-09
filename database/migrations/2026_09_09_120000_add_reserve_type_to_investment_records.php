<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * "Reserva de paz"/"Reserva de oportunidade" deixam de ser uma classe de
 * ativo própria (que obrigava escolher entre "é meu CDB" OU "é minha
 * reserva") e viram uma flag independente em cada investimento — CDB,
 * Tesouro, Fundo, o que for, pode contar pra reserva sem perder a
 * identidade real do produto. A reserva passa a somar TODOS os
 * investimentos marcados (não mais um só via linked_investment_id —
 * nenhuma reserva de verdade cabe inteira num FK só, ver caso real que
 * motivou isso: reserva de paz espalhada em vários certificados de CDB).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('investment_records', function (Blueprint $table) {
            $table->string('reserve_type', 20)->nullable()->after('asset_class');
            $table->index(['profile_id', 'member_id', 'reserve_type']);
        });

        // Nenhuma linha em produção usa essas duas classes até agora (só
        // apareciam em seed de desenvolvimento) — reclassifica pra CDB (o
        // mais comum em renda fixa) só por segurança, caso alguma exista.
        DB::table('investment_records')->where('asset_class', 'reserva_paz')->update([
            'asset_class' => 'cdb',
            'sector' => 'fixed_income',
            'reserve_type' => 'paz',
        ]);
        DB::table('investment_records')->where('asset_class', 'reserva_oportunidade')->update([
            'asset_class' => 'cdb',
            'sector' => 'fixed_income',
            'reserve_type' => 'oportunidade',
        ]);

        Schema::table('financial_reserves', function (Blueprint $table) {
            $table->dropForeign(['linked_investment_id']);
            $table->dropColumn('linked_investment_id');
        });
    }

    public function down(): void
    {
        Schema::table('financial_reserves', function (Blueprint $table) {
            $table->foreignUuid('linked_investment_id')->nullable()->after('current_amount')
                ->constrained('investment_records')->nullOnDelete();
        });

        Schema::table('investment_records', function (Blueprint $table) {
            $table->dropIndex(['investment_records_profile_id_member_id_reserve_type_index']);
            $table->dropColumn('reserve_type');
        });
    }
};
