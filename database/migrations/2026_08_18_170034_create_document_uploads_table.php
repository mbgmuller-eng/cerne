<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('document_uploads', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('profile_id')->constrained('financial_profiles')->cascadeOnDelete();
            $table->foreignUuid('uploaded_by_user_id')->constrained('users');
            $table->foreignUuid('member_id')->nullable()->constrained('profile_members')->nullOnDelete();

            $table->string('document_type', 30);
            $table->string('original_filename', 255);
            $table->text('storage_path');
            $table->unsignedBigInteger('size_bytes')->nullable();

            // Preenchida pela IA a partir do conteúdo do documento.
            $table->string('institution_name', 255)->nullable();
            $table->unsignedTinyInteger('reference_month')->nullable();
            $table->unsignedSmallInteger('reference_year')->nullable();

            $table->string('processing_status', 20)->default('pending');
            $table->unsignedInteger('records_extracted')->nullable();

            // O que a IA extraiu, ANTES de virar lançamento. O usuário
            // revisa e confirma; só então os registros são criados.
            $table->json('extraction_summary')->nullable();

            $table->text('error_message')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamp('committed_at')->nullable();
            $table->timestamps();

            $table->index(['profile_id', 'processing_status']);
            $table->index(['profile_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_uploads');
    }
};
