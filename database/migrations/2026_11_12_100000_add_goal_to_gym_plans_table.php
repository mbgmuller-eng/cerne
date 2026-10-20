<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // O que a pessoa respondeu ao pedir o treino automático. Sem isso o Cerne não sabe há quanto tempo é hora de trocar a
        // fase para aquele objetivo, nem consegue perguntar "seus objetivos continuam os mesmos?" com as respostas à mão.
        Schema::table('gym_plans', function (Blueprint $table) {
            $table->string('goal', 20)->nullable()->after('name');
            $table->string('level', 20)->nullable()->after('goal');
            $table->unsignedTinyInteger('days_per_week')->nullable()->after('level');
            $table->unsignedSmallInteger('session_minutes')->nullable()->after('days_per_week');
            $table->string('equipment_access', 20)->nullable()->after('session_minutes');
            $table->json('restrictions')->nullable()->after('equipment_access');
        });
    }

    public function down(): void
    {
        Schema::table('gym_plans', function (Blueprint $table) {
            $table->dropColumn(['goal', 'level', 'days_per_week', 'session_minutes', 'equipment_access', 'restrictions']);
        });
    }
};
