<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Conta fixa nunca teve necessidade própria — FixedBillService::pay()
     * cravava Necessity::Essential no lançamento gerado, então toda conta
     * fixa virava "essencial" mesmo quando era, por exemplo, um aporte
     * mensal de investimento. Backfill pra 'essential' preserva o
     * comportamento das contas já cadastradas; daqui pra frente o
     * formulário pergunta.
     */
    public function up(): void
    {
        Schema::table('fixed_bills', function (Blueprint $table) {
            $table->string('necessity', 20)->nullable()->after('name');
        });

        DB::table('fixed_bills')->whereNull('necessity')->update(['necessity' => 'essential']);
    }

    public function down(): void
    {
        Schema::table('fixed_bills', function (Blueprint $table) {
            $table->dropColumn('necessity');
        });
    }
};
