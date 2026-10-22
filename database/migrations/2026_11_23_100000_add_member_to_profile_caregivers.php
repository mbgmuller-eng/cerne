<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Cuidador por PESSOA, não por perfil: quem me acompanha vê a MINHA saúde, não a do meu cônjuge que divide o perfil comigo. `member_id` diz de quem
 * é o acesso. Na pessoa acompanhada (um membro só) é o próprio membro dela; as linhas que já existem são preenchidas aqui.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('profile_caregivers', function (Blueprint $table): void {
            $table->foreignUuid('member_id')->nullable()->after('profile_id')->constrained('profile_members')->cascadeOnDelete();
        });

        // Quem já cuida de uma pessoa acompanhada passa a apontar para o único membro dela.
        DB::statement('UPDATE profile_caregivers pc SET member_id = (SELECT pm.id FROM profile_members pm WHERE pm.profile_id = pc.profile_id ORDER BY pm.created_at LIMIT 1) WHERE pc.member_id IS NULL');

        Schema::table('profile_caregivers', function (Blueprint $table): void {
            // Índice próprio de profile_id antes de largar os únicos (a chave estrangeira se apoiava em um deles).
            $table->index('profile_id', 'profile_caregivers_profile_id_index');
        });

        Schema::table('profile_caregivers', function (Blueprint $table): void {
            $table->dropUnique(['profile_id', 'invited_email']);
            $table->dropUnique(['profile_id', 'user_id']);
            $table->unique(['member_id', 'invited_email']);
            $table->unique(['member_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::table('profile_caregivers', function (Blueprint $table): void {
            $table->dropUnique(['member_id', 'invited_email']);
            $table->dropUnique(['member_id', 'user_id']);
            $table->unique(['profile_id', 'invited_email']);
            $table->unique(['profile_id', 'user_id']);
            $table->dropIndex('profile_caregivers_profile_id_index');
            $table->dropConstrainedForeignId('member_id');
        });
    }
};
