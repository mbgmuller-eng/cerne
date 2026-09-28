<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Segundo quadro da foto (início/fim do movimento). Quando os dois
 * existem, a tela alterna entre eles — dá o efeito de GIF sem precisar
 * gerar ou guardar um arquivo animado. Opcional: uma foto só enviada à
 * mão continua funcionando, sem o segundo quadro.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('gym_exercises', function (Blueprint $table) {
            $table->string('image_path_2')->nullable()->after('image_path');
        });
    }

    public function down(): void
    {
        Schema::table('gym_exercises', function (Blueprint $table) {
            $table->dropColumn('image_path_2');
        });
    }
};
