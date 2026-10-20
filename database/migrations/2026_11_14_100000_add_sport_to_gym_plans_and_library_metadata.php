<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Foco esportivo da fase: o esporte, quantas vezes por semana a pessoa o pratica e em que fase da temporada está. O objetivo
        // (`goal`) continua dizendo como o treino é prescrito; o esporte só muda quais exercícios entram.
        Schema::table('gym_plans', function (Blueprint $table) {
            $table->string('sport', 20)->nullable()->after('restrictions');
            $table->unsignedTinyInteger('sport_days')->nullable()->after('sport');
            $table->string('season', 20)->nullable()->after('sport_days');
        });

        // O que a biblioteca de origem diz de cada exercício novo (nível, composto ou isolado, categoria). Serve ao treino automático
        // para liberar só o que cabe e para classificar sem depender do nome.
        Schema::table('gym_exercise_catalog', function (Blueprint $table) {
            $table->string('source_level', 20)->nullable()->after('source_id');
            $table->string('source_mechanic', 20)->nullable()->after('source_level');
            $table->string('source_category', 30)->nullable()->after('source_mechanic');
        });
    }

    public function down(): void
    {
        Schema::table('gym_exercise_catalog', function (Blueprint $table) {
            $table->dropColumn(['source_level', 'source_mechanic', 'source_category']);
        });

        Schema::table('gym_plans', function (Blueprint $table) {
            $table->dropColumn(['sport', 'sport_days', 'season']);
        });
    }
};
