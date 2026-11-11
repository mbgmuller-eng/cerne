<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Duas travas de acesso (revisão de segurança, 2026-11):
 *
 * - `users.password_pending`: a conta foi criada por um profissional com senha aleatória e a pessoa ainda não definiu a dela. É a ÚNICA situação em que o
 *   convite de acesso pode definir senha numa conta que já existe (antes, qualquer conta de cliente aceitava: o consultor vinculado podia trocar a senha
 *   de um cliente e entrar como ele).
 * - `owner_approved_at` em convite de cônjuge e em acesso de familiar: o que o CONSULTOR monta em nome do cliente só vale depois que o dono do perfil
 *   aprova. Sem isso o consultor convidava um e-mail que ele controla e virava "cônjuge" ou "familiar" com login próprio.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->boolean('password_pending')->default(false)->after('password');
        });

        Schema::table('partner_invites', function (Blueprint $table): void {
            $table->timestamp('owner_approved_at')->nullable()->after('accepted_at');
        });

        Schema::table('profile_caregivers', function (Blueprint $table): void {
            $table->timestamp('owner_approved_at')->nullable()->after('granted_declared_at');
        });

        // Contas de cliente que nunca entraram e nunca verificaram o e-mail são as criadas por importação (senha aleatória, sem aviso). Elas continuam
        // podendo ser ativadas pelo convite. Qualquer outra conta fica fechada para esse caminho.
        DB::table('users')->where('role', 'client')->whereNull('last_login_at')->whereNull('email_verified_at')->update(['password_pending' => true]);

        // O que já existe foi emitido sob as regras antigas e segue valendo.
        DB::table('partner_invites')->update(['owner_approved_at' => DB::raw('created_at')]);
        DB::table('profile_caregivers')->update(['owner_approved_at' => DB::raw('COALESCE(created_at, CURRENT_TIMESTAMP)')]);
    }

    public function down(): void
    {
        Schema::table('profile_caregivers', fn (Blueprint $table) => $table->dropColumn('owner_approved_at'));
        Schema::table('partner_invites', fn (Blueprint $table) => $table->dropColumn('owner_approved_at'));
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn('password_pending'));
    }
};
