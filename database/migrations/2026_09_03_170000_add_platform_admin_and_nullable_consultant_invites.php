<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('is_platform_admin')->default(false)->after('role');
        });

        // consultant_id precisa aceitar NULL: convite emitido pelo painel
        // admin, sem vínculo de consultor nenhum (ver ClientInviteService::
        // sendStandalone()). doctrine/dbal não está instalado, então
        // Blueprint::nullable()->change() não funciona aqui — SQL cru
        // resolve sem trazer essa dependência nova.
        Schema::table('consultant_invites', function (Blueprint $table) {
            $table->dropForeign(['consultant_id']);
        });

        DB::statement('ALTER TABLE consultant_invites MODIFY consultant_id CHAR(36) NULL');

        Schema::table('consultant_invites', function (Blueprint $table) {
            $table->foreign('consultant_id')->references('id')->on('users')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('consultant_invites', function (Blueprint $table) {
            $table->dropForeign(['consultant_id']);
        });

        DB::statement('ALTER TABLE consultant_invites MODIFY consultant_id CHAR(36) NOT NULL');

        Schema::table('consultant_invites', function (Blueprint $table) {
            $table->foreign('consultant_id')->references('id')->on('users')->cascadeOnDelete();
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('is_platform_admin');
        });
    }
};
