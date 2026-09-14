<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "Quem criou este lançamento" nunca tinha ON DELETE definido — o padrão do
 * MySQL é RESTRICT, então excluir uma conta pela tela de admin (AdminUsers::
 * excluirConta()) quebrava com erro 1451 assim que a pessoa tinha QUALQUER
 * lançamento próprio (o caso comum: quase todo mundo lança os próprios
 * gastos). Virou nullOnDelete, mesmo padrão já usado em profile_members.
 * user_id — o registro em si continua existindo (some via profile_id
 * cascade, se for o caso), só perde a referência de quem criou.
 */
return new class extends Migration
{
    private const array COLUNAS = [
        ['expense_records', 'created_by_user_id'],
        ['income_records', 'created_by_user_id'],
        ['installment_groups', 'created_by_user_id'],
        ['investment_records', 'created_by_user_id'],
        ['investment_transactions', 'created_by_user_id'],
        ['insurance_policies', 'created_by_user_id'],
        ['goals', 'created_by_user_id'],
        ['document_uploads', 'uploaded_by_user_id'],
        ['partner_invites', 'invited_by_user_id'],
        ['audit_logs', 'user_id'],
    ];

    public function up(): void
    {
        foreach (self::COLUNAS as [$tabela, $coluna]) {
            Schema::table($tabela, function (Blueprint $table) use ($coluna) {
                $table->dropForeign([$coluna]);
            });

            // MODIFY direto em SQL — change() do Schema builder exige
            // doctrine/dbal, que não está instalado neste projeto.
            DB::statement("ALTER TABLE `{$tabela}` MODIFY `{$coluna}` CHAR(36) NULL");

            Schema::table($tabela, function (Blueprint $table) use ($coluna) {
                $table->foreign($coluna)->references('id')->on('users')->nullOnDelete();
            });
        }
    }

    public function down(): void
    {
        foreach (self::COLUNAS as [$tabela, $coluna]) {
            Schema::table($tabela, function (Blueprint $table) use ($coluna) {
                $table->dropForeign([$coluna]);
            });

            DB::statement("ALTER TABLE `{$tabela}` MODIFY `{$coluna}` CHAR(36) NOT NULL");

            Schema::table($tabela, function (Blueprint $table) use ($coluna) {
                $table->foreign($coluna)->references('id')->on('users');
            });
        }
    }
};
