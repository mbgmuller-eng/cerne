<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Importação parcial: a pessoa pode confirmar só o que já categorizou e
     * voltar depois pro resto, sem perder o trabalho já feito. Precisa
     * lembrar, por item extraído (índice dentro de extraction_summary),
     * dois destinos possíveis — já virou lançamento, ou foi marcado pra
     * nunca virar — porque nenhum dos dois pode ser reoferecido de novo na
     * próxima revisão. O documento só vira "Importado" quando todo item
     * tiver um dos dois (ver DocumentUpload::isFullyResolved()).
     */
    public function up(): void
    {
        Schema::table('document_uploads', function (Blueprint $table) {
            $table->json('imported_item_indices')->nullable()->after('extraction_summary');
            $table->json('excluded_item_indices')->nullable()->after('imported_item_indices');
        });
    }

    public function down(): void
    {
        Schema::table('document_uploads', function (Blueprint $table) {
            $table->dropColumn(['imported_item_indices', 'excluded_item_indices']);
        });
    }
};
