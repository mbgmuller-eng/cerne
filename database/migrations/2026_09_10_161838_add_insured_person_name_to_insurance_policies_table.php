<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('insurance_policies', function (Blueprint $table) {
            // Pra quando a apólice é de alguém que não é titular nem
            // cônjuge cadastrado (ex.: filha, outro dependente) — o app
            // só tem papel de titular/cônjuge em ProfileMember, sem
            // dependente. Preenchido só quando member_id é nulo (ver
            // InsurancePolicy::personGroupKey()); com member_id, esta
            // coluna fica sempre nula.
            $table->string('insured_person_name', 255)->nullable()->after('member_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('insurance_policies', function (Blueprint $table) {
            $table->dropColumn('insured_person_name');
        });
    }
};
