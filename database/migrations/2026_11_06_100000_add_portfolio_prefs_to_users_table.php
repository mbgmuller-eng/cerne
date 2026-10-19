<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Ordenação padrão das listas da carteira do consultor ({"clients": {"sort": "nome", "dir": "asc"}, "no_life": {...}}).
            $table->json('portfolio_prefs')->nullable()->after('calendar_view');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('portfolio_prefs');
        });
    }
};
