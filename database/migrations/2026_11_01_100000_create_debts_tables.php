<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Módulo Dívidas: financiamentos, empréstimos (e, nas próximas fases, consórcios e dívidas em aberto) com o cronograma de parcelas.
 *
 * Cada parcela paga fica LIGADA a uma despesa do fluxo de caixa (`expense_id`), com a categoria do momento: o contrato guarda uma
 * classificação padrão, mas é a despesa que carrega a categoria de cada pagamento. O índice único em `expense_id` impede que uma
 * mesma despesa pague duas parcelas.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('debts', function (Blueprint $table): void {
            $table->char('id', 36)->primary();
            $table->char('profile_id', 36);
            $table->char('member_id', 36)->nullable();
            $table->boolean('is_private')->default(false);
            $table->string('kind', 20);
            $table->string('status', 20)->default('active');
            $table->string('name', 120);
            $table->string('creditor', 120)->nullable();
            $table->decimal('principal', 15, 2)->nullable();
            $table->string('amortization', 10)->default('fixed');
            $table->decimal('rate', 9, 6)->nullable();
            $table->string('rate_basis', 20)->nullable();
            $table->unsignedSmallInteger('term');
            $table->date('first_due_date');
            $table->decimal('installment_amount', 15, 2)->nullable();
            $table->decimal('monthly_fees', 15, 2)->default(0);
            $table->unsignedSmallInteger('paid_before')->default(0);
            $table->string('necessity', 20)->default('essential');
            $table->char('category_id', 36)->nullable();
            $table->char('subcategory_id', 36)->nullable();
            $table->char('bank_account_id', 36)->nullable();
            $table->text('notes')->nullable();
            $table->char('created_by_user_id', 36)->nullable();
            $table->timestamps();

            $table->foreign('profile_id')->references('id')->on('financial_profiles')->cascadeOnDelete();
            $table->index(['profile_id', 'status'], 'debts_profile_status_idx');
        });

        Schema::create('debt_installments', function (Blueprint $table): void {
            $table->char('id', 36)->primary();
            $table->char('profile_id', 36);
            $table->char('debt_id', 36);
            $table->char('member_id', 36)->nullable();
            $table->boolean('is_private')->default(false);
            $table->unsignedSmallInteger('number');
            $table->date('due_date');
            $table->decimal('principal_amount', 15, 2)->default(0);
            $table->decimal('interest_amount', 15, 2)->default(0);
            $table->decimal('fees_amount', 15, 2)->default(0);
            $table->decimal('amount', 15, 2);
            $table->string('status', 10)->default('open');
            $table->date('paid_on')->nullable();
            $table->decimal('paid_amount', 15, 2)->nullable();
            $table->char('expense_id', 36)->nullable();
            $table->timestamps();

            $table->foreign('profile_id')->references('id')->on('financial_profiles')->cascadeOnDelete();
            $table->foreign('debt_id')->references('id')->on('debts')->cascadeOnDelete();
            $table->foreign('expense_id')->references('id')->on('expense_records')->nullOnDelete();
            $table->unique(['debt_id', 'number'], 'debt_installments_number_unique');
            $table->unique('expense_id', 'debt_installments_expense_unique');
            $table->index(['profile_id', 'status', 'due_date'], 'debt_installments_due_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('debt_installments');
        Schema::dropIfExists('debts');
    }
};
