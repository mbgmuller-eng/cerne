<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Módulo Dívidas, fase 2: consórcio e dívida em aberto.
 *
 * Consórcio: a carta (crédito), o índice e a periodicidade do reajuste, a parcela flex e os dados da contemplação (inclusive o novo
 * valor da parcela, que muda depois dela). Dívida em aberto: o saldo atual (que ainda precisa ser negociado) e, quando houver, o
 * acordo, com o valor original e o desconto obtido. Contrato sem cronograma (dívida em aberto) não tem prazo nem vencimento.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('debts', function (Blueprint $table): void {
            $table->unsignedSmallInteger('term')->default(0)->change();
            $table->date('first_due_date')->nullable()->change();

            // Consórcio
            $table->string('consortium_type', 10)->nullable()->after('kind');
            $table->string('consortium_group', 40)->nullable()->after('consortium_type');
            $table->decimal('credit_amount', 15, 2)->nullable()->after('principal');
            $table->boolean('is_flex')->default(false)->after('credit_amount');
            $table->string('adjustment_index', 10)->nullable()->after('is_flex');
            $table->unsignedSmallInteger('adjustment_every_months')->nullable()->after('adjustment_index');
            $table->unsignedSmallInteger('adjustment_first_after_months')->nullable()->after('adjustment_every_months');
            $table->date('next_adjustment_on')->nullable()->after('adjustment_first_after_months');
            $table->date('contemplated_on')->nullable()->after('next_adjustment_on');
            $table->string('contemplation_mode', 15)->nullable()->after('contemplated_on');
            $table->decimal('bid_amount', 15, 2)->nullable()->after('contemplation_mode');
            $table->decimal('credit_released', 15, 2)->nullable()->after('bid_amount');

            // Dívida em aberto
            $table->decimal('current_balance', 15, 2)->nullable()->after('credit_released');
            $table->date('balance_updated_on')->nullable()->after('current_balance');
            $table->date('agreement_on')->nullable()->after('balance_updated_on');
            $table->decimal('agreement_total', 15, 2)->nullable()->after('agreement_on');
            $table->decimal('agreement_discount', 15, 2)->nullable()->after('agreement_total');
        });

        // Cada reajuste da carta fica registrado: o índice aplicado e a carta e a parcela antes e depois.
        Schema::create('debt_adjustments', function (Blueprint $table): void {
            $table->char('id', 36)->primary();
            $table->char('profile_id', 36);
            $table->char('debt_id', 36);
            $table->char('member_id', 36)->nullable();
            $table->boolean('is_private')->default(false);
            $table->date('applied_on');
            $table->decimal('percent', 9, 4);
            $table->decimal('credit_before', 15, 2)->nullable();
            $table->decimal('credit_after', 15, 2)->nullable();
            $table->decimal('installment_before', 15, 2)->nullable();
            $table->decimal('installment_after', 15, 2)->nullable();
            $table->char('created_by_user_id', 36)->nullable();
            $table->timestamps();

            $table->foreign('profile_id')->references('id')->on('financial_profiles')->cascadeOnDelete();
            $table->foreign('debt_id')->references('id')->on('debts')->cascadeOnDelete();
            $table->index(['debt_id', 'applied_on'], 'debt_adjustments_debt_idx');
        });

        // O objetivo ligado a um consórcio avança por parcelas pagas.
        Schema::table('goals', function (Blueprint $table): void {
            $table->char('linked_debt_id', 36)->nullable()->after('linked_investment_id');
            $table->foreign('linked_debt_id')->references('id')->on('debts')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('goals', function (Blueprint $table): void {
            $table->dropForeign(['linked_debt_id']);
            $table->dropColumn('linked_debt_id');
        });

        Schema::dropIfExists('debt_adjustments');

        Schema::table('debts', function (Blueprint $table): void {
            $table->dropColumn([
                'consortium_type', 'consortium_group', 'credit_amount', 'is_flex', 'adjustment_index', 'adjustment_every_months',
                'adjustment_first_after_months', 'next_adjustment_on', 'contemplated_on', 'contemplation_mode', 'bid_amount',
                'credit_released', 'current_balance', 'balance_updated_on', 'agreement_on', 'agreement_total', 'agreement_discount',
            ]);
        });
    }
};
