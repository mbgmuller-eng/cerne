<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('expense_records', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('profile_id')->constrained('financial_profiles')->cascadeOnDelete();

            // Nulo = gasto da família, visível a todos os membros.
            $table->foreignUuid('member_id')->nullable()->constrained('profile_members')->nullOnDelete();

            $table->string('description', 255);

            // A necessidade é do LANÇAMENTO, não da categoria.
            $table->string('necessity', 20);

            $table->foreignUuid('category_id')->constrained('expense_categories');
            $table->foreignUuid('subcategory_id')->nullable()->constrained('expense_subcategories')->nullOnDelete();

            $table->decimal('amount', 15, 2);
            $table->date('expense_date');
            $table->unsignedSmallInteger('year');
            $table->unsignedTinyInteger('month');

            // Débito OU crédito — nunca os dois (validado no model).
            $table->foreignUuid('bank_account_id')->nullable()->constrained('bank_accounts')->nullOnDelete();
            $table->foreignUuid('credit_card_id')->nullable()->constrained('credit_cards')->nullOnDelete();
            $table->foreignUuid('credit_card_invoice_id')->nullable()->constrained('credit_card_invoices')->nullOnDelete();

            // Nulo = pagamento único.
            $table->foreignUuid('installment_group_id')->nullable()->constrained('installment_groups')->cascadeOnDelete();
            $table->unsignedSmallInteger('installment_number')->nullable();

            $table->text('notes')->nullable();
            $table->uuid('source_document_id')->nullable();
            $table->foreignUuid('created_by_user_id')->constrained('users');
            $table->timestamps();

            $table->index(['profile_id', 'year', 'month']);
            $table->index(['profile_id', 'member_id']);
            $table->index(['profile_id', 'necessity']);
            $table->index('credit_card_invoice_id');
            $table->index('installment_group_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('expense_records');
    }
};
