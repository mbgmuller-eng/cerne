<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Regra de consórcio por administradora (commission_rules, kind = 'consortium'): percentual da carta e em quais parcelas ele é pago.
        Schema::table('commission_rules', function (Blueprint $table) {
            $table->decimal('credit_percent', 7, 4)->nullable()->after('annual_percent');
            // "2, 4, 6" = partes iguais nessas parcelas; "2:40, 4:30, 6:30" = divisão em % da comissão.
            $table->string('installment_schedule', 80)->nullable()->after('credit_percent');
        });

        // Consórcio de um cliente em que o profissional tem comissão: ele vendeu (usa a regra da administradora) ou um terceiro
        // vendeu (vale só o percentual combinado que ele informar).
        Schema::create('debt_commissions', function (Blueprint $table) {
            $table->char('id', 36)->primary();
            $table->foreignUuid('professional_id')->constrained('users')->cascadeOnDelete();
            $table->char('debt_id', 36);
            $table->string('role', 12)->default('sold');
            $table->decimal('percent_override', 7, 4)->nullable();
            $table->string('schedule_override', 80)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->foreign('debt_id')->references('id')->on('debts')->cascadeOnDelete();
            $table->unique(['professional_id', 'debt_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('debt_commissions');
        Schema::table('commission_rules', function (Blueprint $table) {
            $table->dropColumn(['credit_percent', 'installment_schedule']);
        });
    }
};
