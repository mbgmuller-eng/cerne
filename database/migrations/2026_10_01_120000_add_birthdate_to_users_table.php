<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Pra profissional (consultor/corretor), que não tem ProfileMember — é
 * aqui que a data de nascimento dele mora. Cliente não usa esta coluna:
 * a dele já vive em profile_members.birthdate desde sempre, que é o
 * campo que ImportantDatesService::notifyUpcomingBirthdays() já lê.
 * Duplicar nas duas tabelas pro cliente só criaria duas fontes de
 * verdade pra manter sincronizadas.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->date('birthdate')->nullable()->after('phone');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('birthdate');
        });
    }
};
