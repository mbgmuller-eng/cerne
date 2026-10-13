<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Arquivos do lead: a simulação em PDF de cada cotação e documentos gerais (CNH, CRLV...). Ficam no disco privado,
     * só abrem para o dono do lead, e saem do servidor quando o lead é excluído ou passa do prazo depois de perdido.
     */
    public function up(): void
    {
        Schema::create('lead_attachments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('lead_id')->constrained('leads')->cascadeOnDelete();
            // Nulo = anexo do lead em geral; preenchido = a simulação daquela cotação.
            $table->foreignUuid('lead_quote_id')->nullable()->constrained('lead_quotes')->cascadeOnDelete();

            $table->string('original_filename', 255);
            $table->string('storage_path', 500);
            $table->string('mime_type', 100);
            $table->unsignedInteger('size_bytes');

            $table->foreignUuid('uploaded_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['lead_id', 'lead_quote_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lead_attachments');
    }
};
