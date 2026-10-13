<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * De qual cotação aceita de um lead a apólice nasceu. É só rastreio interno do profissional: ao apagar o lead (e
     * com ele a cotação) a apólice fica, sem o vínculo. Cada cotação gera no máximo uma apólice (índice único).
     */
    public function up(): void
    {
        Schema::table('insurance_policies', function (Blueprint $table) {
            $table->foreignUuid('source_lead_quote_id')->nullable()->after('source_document_id')
                ->constrained('lead_quotes')->nullOnDelete();
            $table->unique('source_lead_quote_id');
        });
    }

    public function down(): void
    {
        Schema::table('insurance_policies', function (Blueprint $table) {
            $table->dropForeign(['source_lead_quote_id']);
            $table->dropUnique(['source_lead_quote_id']);
            $table->dropColumn('source_lead_quote_id');
        });
    }
};
