<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // A visão do calendário (mês, semana ou dia) que a pessoa escolheu por último: vale nas agendas e em qualquer aparelho.
            $table->string('calendar_view', 10)->default('month')->after('agenda_layout');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('calendar_view');
        });
    }
};
