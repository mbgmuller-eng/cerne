<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Acompanhamento, fase 3: reuniões recorrentes (uma série de reuniões criadas juntas) e anexos da área de Documentos.
 *
 * O anexo é uma REFERÊNCIA a um documento do cofre, não uma cópia: quem pode abrir o arquivo continua sendo decidido pela
 * visibilidade do próprio documento (DocumentVisibilityScope), então anexar nunca expõe o que o consultor não poderia ver.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('engagement_meetings', function (Blueprint $table): void {
            $table->char('series_id', 36)->nullable()->after('consultant_id');
            $table->index('series_id', 'eng_meetings_series_idx');
        });

        Schema::create('engagement_attachments', function (Blueprint $table): void {
            $table->char('id', 36)->primary();
            $table->char('profile_id', 36);
            $table->char('consultant_id', 36);
            $table->string('subject_type', 10);
            $table->char('subject_id', 36);
            $table->char('document_id', 36);
            $table->char('attached_by_user_id', 36)->nullable();
            $table->timestamps();

            $table->foreign('profile_id')->references('id')->on('financial_profiles')->cascadeOnDelete();
            $table->foreign('consultant_id')->references('id')->on('users')->cascadeOnDelete();
            $table->foreign('document_id')->references('id')->on('documents')->cascadeOnDelete();
            $table->unique(['subject_type', 'subject_id', 'document_id'], 'eng_attachments_unique');
            $table->index(['profile_id', 'consultant_id'], 'eng_attachments_relation_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('engagement_attachments');

        Schema::table('engagement_meetings', function (Blueprint $table): void {
            $table->dropIndex('eng_meetings_series_idx');
            $table->dropColumn('series_id');
        });
    }
};
