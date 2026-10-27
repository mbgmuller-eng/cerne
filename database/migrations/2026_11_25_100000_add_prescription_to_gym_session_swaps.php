<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A troca "só por hoje" passa a ter a meta do exercício de hoje (séries, repetições, pausa, observação), em vez de herdar a
        // do exercício que saiu. Nulo = troca antiga, feita antes disto: a tela segue mostrando a meta do item.
        Schema::table('gym_session_swaps', function (Blueprint $table) {
            $table->unsignedSmallInteger('target_sets')->nullable()->after('exercise_id');
            $table->unsignedSmallInteger('target_reps_min')->nullable()->after('target_sets');
            $table->unsignedSmallInteger('target_reps_max')->nullable()->after('target_reps_min');
            $table->unsignedInteger('target_duration_seconds')->nullable()->after('target_reps_max');
            $table->unsignedSmallInteger('rest_seconds')->nullable()->after('target_duration_seconds');
            $table->text('notes')->nullable()->after('rest_seconds');
            $table->foreignUuid('default_equipment_id')->nullable()->after('notes')->constrained('gym_equipment')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('gym_session_swaps', function (Blueprint $table) {
            $table->dropConstrainedForeignId('default_equipment_id');
            $table->dropColumn(['target_sets', 'target_reps_min', 'target_reps_max', 'target_duration_seconds', 'rest_seconds', 'notes']);
        });
    }
};