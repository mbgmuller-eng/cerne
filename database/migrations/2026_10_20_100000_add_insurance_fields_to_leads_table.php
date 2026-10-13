<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            // Funil em que o lead anda. Os que já existem são de consultoria (o único funil até aqui).
            $table->string('kind', 15)->default('consulting')->after('consultant_id');

            // Tipos de seguro de interesse (valores de InsuranceType) e, por tipo, os dados que a cotação pede
            // (fuma, veículo, CEP...). JSON: variam por tipo e só quem cota lê.
            $table->json('interests')->nullable()->after('phone');
            $table->json('quote_data')->nullable()->after('interests');

            // Aniversário do contato: serve de dado de cotação (vida) e de aviso de relacionamento.
            $table->date('birth_date')->nullable()->after('quote_data');

            $table->string('source', 20)->nullable()->after('birth_date');
            $table->string('source_detail', 255)->nullable()->after('source');

            // Motivo categorizado (o texto livre continua em lost_reason) e quando foi perdido: é daí que conta o
            // prazo para apagar os anexos.
            $table->string('lost_category', 20)->nullable()->after('lost_reason');
            $table->timestamp('lost_at')->nullable()->after('lost_category');

            $table->index(['consultant_id', 'kind', 'stage'], 'leads_consultant_kind_stage_index');
        });
    }

    public function down(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            $table->dropIndex('leads_consultant_kind_stage_index');
            $table->dropColumn(['kind', 'interests', 'quote_data', 'birth_date', 'source', 'source_detail', 'lost_category', 'lost_at']);
        });
    }
};
