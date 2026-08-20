<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('investment_records', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('profile_id')->constrained('financial_profiles')->cascadeOnDelete();
            $table->foreignUuid('member_id')->constrained('profile_members')->cascadeOnDelete();

            $table->string('sector', 30);
            $table->string('asset_class', 30);
            $table->string('ticker', 20)->nullable();
            $table->string('name', 255);
            $table->string('institution', 255)->nullable();

            $table->decimal('current_amount', 15, 2)->default(0);
            $table->decimal('invested_amount', 15, 2)->nullable();

            // 6 casas: cripto e fundos operam com frações pequenas, e
            // arredondar aqui distorce o preço médio ao longo do tempo.
            $table->decimal('average_price', 15, 6)->nullable();
            $table->decimal('quantity', 15, 6)->nullable();

            $table->date('purchase_date')->nullable();
            $table->date('maturity_date')->nullable();
            $table->string('return_rate', 50)->nullable();
            $table->string('return_rate_type', 30)->nullable();
            $table->foreignUuid('broker_account_id')->nullable()->constrained('bank_accounts')->nullOnDelete();

            // Campos do Open Finance (seção 11 — v2, não bloqueia o MVP).
            // Criados agora para que a integração não exija migration depois.
            $table->string('source', 20)->default('manual');
            $table->string('external_asset_id')->nullable();
            $table->boolean('is_locked_by_sync')->default(false);

            $table->boolean('is_active')->default(true);
            $table->text('notes')->nullable();
            $table->uuid('source_document_id')->nullable();
            $table->foreignUuid('created_by_user_id')->constrained('users');
            $table->timestamps();

            $table->index(['profile_id', 'is_active']);
            $table->index(['profile_id', 'sector']);
            $table->index(['profile_id', 'member_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('investment_records');
    }
};
