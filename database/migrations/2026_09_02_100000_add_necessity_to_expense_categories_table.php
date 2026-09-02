<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Nulo = categoria disponível pra qualquer necessidade (as 12
     * categorias padrão continuam assim). Um valor específico restringe a
     * categoria a só aparecer quando aquela necessidade for a escolhida —
     * é o que dá lugar pra "Investimentos" existir sem poluir a lista de
     * quem escolheu Essencial/Supérfluo.
     */
    public function up(): void
    {
        Schema::table('expense_categories', function (Blueprint $table) {
            $table->string('necessity', 20)->nullable()->after('name');
        });
    }

    public function down(): void
    {
        Schema::table('expense_categories', function (Blueprint $table) {
            $table->dropColumn('necessity');
        });
    }
};
