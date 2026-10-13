<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Leitura da simulação em PDF por IA. O resultado fica no próprio anexo, aguardando a revisão: ele só vira cotação
     * quando o profissional confere o formulário pré-preenchido e salva (extraction_applied_at marca que isso ocorreu).
     */
    public function up(): void
    {
        Schema::table('lead_attachments', function (Blueprint $table) {
            $table->string('extraction_status', 20)->nullable()->after('uploaded_by_user_id');
            $table->timestamp('extraction_requested_at')->nullable()->after('extraction_status');
            $table->json('extraction_summary')->nullable()->after('extraction_requested_at');
            $table->string('extraction_error', 500)->nullable()->after('extraction_summary');
            $table->timestamp('extraction_applied_at')->nullable()->after('extraction_error');

            // Teto diário por profissional: conta as leituras pedidas hoje.
            $table->index('extraction_requested_at');
        });
    }

    public function down(): void
    {
        Schema::table('lead_attachments', function (Blueprint $table) {
            $table->dropIndex(['extraction_requested_at']);
            $table->dropColumn(['extraction_status', 'extraction_requested_at', 'extraction_summary', 'extraction_error', 'extraction_applied_at']);
        });
    }
};
