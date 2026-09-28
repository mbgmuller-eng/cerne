<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Foto do exercício, pra reconhecer de relance qual é qual durante o
 * treino. Fica no exercício do catálogo (não no item do treino): a mesma
 * foto vale em todos os treinos e fases que usam aquele exercício.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('gym_exercises', function (Blueprint $table) {
            $table->string('image_path')->nullable()->after('measure_type');
        });
    }

    public function down(): void
    {
        Schema::table('gym_exercises', function (Blueprint $table) {
            $table->dropColumn('image_path');
        });
    }
};
