<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Pastas e subpastas em Documentos, criadas pelo próprio usuário (sugestão de quem
 * testa o app): Propriedades > Fazenda Santa Maria > Escritura, Veículos, Família...
 *
 * Pasta é só ORGANIZAÇÃO. Quem enxerga cada documento continua sendo decidido pela
 * categoria dele (DocumentVisibilityScope), nunca pela pasta onde ele está.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_folders', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('profile_id')->constrained('financial_profiles')->cascadeOnDelete();
            $table->foreignUuid('parent_id')->nullable()->constrained('document_folders')->nullOnDelete();
            $table->string('name', 80);
            $table->timestamps();

            $table->index(['profile_id', 'parent_id']);
        });

        Schema::table('documents', function (Blueprint $table): void {
            // Apagar uma pasta move o conteúdo para a pasta de cima (DocumentFolderService::delete);
            // o nullOnDelete é só a rede de segurança: o documento nunca some junto com a pasta.
            $table->foreignUuid('folder_id')->nullable()->after('member_id')->constrained('document_folders')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('documents', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('folder_id');
        });

        Schema::dropIfExists('document_folders');
    }
};
