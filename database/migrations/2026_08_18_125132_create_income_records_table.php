<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('income_records', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('profile_id')->constrained('financial_profiles')->cascadeOnDelete();

            // Nulo = receita da família, visível a todos os membros.
            $table->foreignUuid('member_id')->nullable()->constrained('profile_members')->nullOnDelete();

            $table->foreignUuid('category_id')->constrained('income_categories');
            $table->string('description', 255)->nullable();
            $table->decimal('amount', 15, 2);
            $table->date('received_date');

            // Desnormalizados de propósito: os dashboards agregam por
            // competência e funções de data em WHERE impedem o uso do índice.
            $table->unsignedSmallInteger('year');
            $table->unsignedTinyInteger('month');

            $table->foreignUuid('bank_account_id')->nullable()->constrained('bank_accounts')->nullOnDelete();
            $table->boolean('is_recurring')->default(false);
            $table->text('notes')->nullable();
            $table->uuid('source_document_id')->nullable();
            $table->foreignUuid('created_by_user_id')->constrained('users');
            $table->timestamps();

            $table->index(['profile_id', 'year', 'month']);
            $table->index(['profile_id', 'member_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('income_records');
    }
};
