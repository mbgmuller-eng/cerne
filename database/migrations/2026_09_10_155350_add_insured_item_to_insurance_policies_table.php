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
            // Qual bem a apólice cobre (o carro, o aparelho, o imóvel) —
            // só faz sentido pra Carro/Eletrônicos/Residencia, texto livre
            // porque não existe cadastro de bens no app.
            $table->string('insured_item', 255)->nullable()->after('policy_number');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('insurance_policies', function (Blueprint $table) {
            $table->dropColumn('insured_item');
        });
    }
};
