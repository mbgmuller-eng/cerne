<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Chave de acesso do QR Code de emergência — nunca os dados de saúde em
 * si, só um token opaco que resolve pra UMA ficha (ver HealthEmergency-
 * Controller). "Gerar novo código" troca este valor: o QR Code antigo
 * (impresso, colado onde for) para de funcionar sozinho — é a forma
 * simples de revogar, sem precisar de um sistema de expiração.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('health_cards', function (Blueprint $table) {
            $table->string('emergency_token', 40)->nullable()->unique()->after('blood_type');
        });
    }

    public function down(): void
    {
        Schema::table('health_cards', function (Blueprint $table) {
            $table->dropColumn('emergency_token');
        });
    }
};
