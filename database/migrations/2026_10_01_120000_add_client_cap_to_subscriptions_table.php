<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Teto de clientes vinculados e ativos que uma assinatura Professional
 * cobre — ver ConsultantCapacityService. Só faz sentido pra
 * kind=Professional (Direct cobre só o próprio dono, sem teto).
 *
 * Nulo de propósito, sem default: toda assinatura já existente (inclusive
 * a de cortesia) nasce sem teto, exatamente o comportamento que já tinham
 * antes desta coluna existir.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('subscriptions', function (Blueprint $table): void {
            $table->unsignedInteger('client_cap')->nullable()->after('bundle');
        });
    }

    public function down(): void
    {
        Schema::table('subscriptions', function (Blueprint $table): void {
            $table->dropColumn('client_cap');
        });
    }
};
