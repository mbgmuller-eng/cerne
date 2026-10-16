<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('debts', function (Blueprint $table) {
            // O quanto a parcela do consórcio flex é menor até a contemplação (10 a 50%).
            $table->unsignedTinyInteger('flex_percent')->nullable()->after('is_flex');
            $table->string('consortium_contract', 40)->nullable()->after('consortium_group');
            // O que o último extrato da administradora disse: serve para estimar a parcela cheia depois da contemplação.
            $table->date('statement_on')->nullable()->after('next_adjustment_on');
            $table->decimal('statement_balance', 15, 2)->nullable()->after('statement_on');
            $table->unsignedSmallInteger('statement_remaining')->nullable()->after('statement_balance');
            $table->decimal('insurance_percent', 9, 6)->nullable()->after('statement_remaining');
            $table->date('last_adjustment_on')->nullable()->after('insurance_percent');
        });
    }

    public function down(): void
    {
        Schema::table('debts', function (Blueprint $table) {
            $table->dropColumn(['flex_percent', 'consortium_contract', 'statement_on', 'statement_balance', 'statement_remaining', 'insurance_percent', 'last_adjustment_on']);
        });
    }
};
