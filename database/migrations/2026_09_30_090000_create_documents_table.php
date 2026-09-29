<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('documents', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignUuid('profile_id')->constrained('financial_profiles')->cascadeOnDelete();
            // Nulo = documento da família (ex.: comprovante de residência),
            // sem pessoa específica — mesmo raciocínio de InsurancePolicy::member_id.
            $table->foreignUuid('member_id')->nullable()->constrained('profile_members')->nullOnDelete();
            $table->string('category', 30);
            // Só preenchido quando category = insurance_policy — o PDF
            // herda a visibilidade da apólice em vez de ter flag própria
            // (ver DocumentVisibilityScope).
            $table->foreignUuid('insurance_policy_id')->nullable()->constrained('insurance_policies')->nullOnDelete();
            $table->string('title', 120);
            $table->string('original_filename', 255);
            $table->string('storage_path', 255);
            $table->string('mime_type', 100);
            $table->unsignedInteger('size_bytes');
            $table->date('expires_on')->nullable();
            // Só tem efeito quando category = other (ver DocumentVisibilityScope) —
            // toda outra categoria decide visibilidade sozinha, sem depender desta coluna.
            $table->boolean('visible_to_professional')->default(false);
            $table->foreignUuid('created_by_member_id')->nullable()->constrained('profile_members')->nullOnDelete();
            $table->timestamps();

            $table->index(['profile_id', 'category']);
            $table->index('member_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('documents');
    }
};
