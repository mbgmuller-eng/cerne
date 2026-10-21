<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** O histórico de mudança de remédio guarda o texto de antes e de depois: é dado de saúde como o próprio remédio. Ver a migration anterior. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('health_medication_changes', function (Blueprint $table) {
            $table->text('old_value')->nullable()->change();
            $table->text('new_value')->nullable()->change();
        });
    }

    public function down(): void
    {
        // Não se desfaz: voltar a string curta truncaria o texto cifrado.
    }
};
