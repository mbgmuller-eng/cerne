<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Passo a passo de cada exercício do catálogo (lista de frases, em JSON) e a origem dos que vieram da biblioteca pública.
 *
 * `source_id` é o id do exercício na biblioteca de origem: é ele que deixa a importação rodar de novo sem duplicar.
 * `in_auto_workout` diz se o treino automático pode sortear o exercício: os que já existiam (escolhidos à mão) seguem
 * valendo; os importados ficam de fora até alguém decidir o contrário, porque a biblioteca traz de levantamento de pedra
 * Atlas a alongamento e o gerador foi afinado só com a lista curada.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('gym_exercise_catalog', function (Blueprint $table) {
            $table->json('instructions')->nullable()->after('notes');
            $table->string('source_id', 120)->nullable()->after('instructions');
            $table->boolean('in_auto_workout')->default(true)->after('is_active');

            $table->index('source_id');
        });
    }

    public function down(): void
    {
        Schema::table('gym_exercise_catalog', function (Blueprint $table) {
            $table->dropIndex(['source_id']);
            $table->dropColumn(['instructions', 'source_id', 'in_auto_workout']);
        });
    }
};
