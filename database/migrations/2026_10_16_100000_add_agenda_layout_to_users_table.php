<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // 'list' por padrão: a Agenda de Saúde continua abrindo como sempre abriu
            // até a pessoa escolher o calendário primeiro — ver App\Enums\AgendaLayout.
            $table->string('agenda_layout', 10)->default('list')->after('theme');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('agenda_layout');
        });
    }
};
