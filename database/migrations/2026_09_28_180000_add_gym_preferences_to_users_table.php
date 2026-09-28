<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Interruptores GLOBAIS da academia (valem pra conta, não pra cada treino):
 * manter a tela ligada durante a sessão, vibrar e tocar som quando a pausa
 * acaba. Tela ligada nasce ligada — sem ela o celular bloqueia no meio da
 * série; vibração e som são opt-in porque incomodam em academia lotada.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('gym_keep_awake')->default(true)->after('notify_push_enabled');
            $table->boolean('gym_vibrate')->default(false)->after('gym_keep_awake');
            $table->boolean('gym_sound')->default(false)->after('gym_vibrate');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['gym_keep_awake', 'gym_vibrate', 'gym_sound']);
        });
    }
};
