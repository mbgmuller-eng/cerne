<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Quem usa o Cerne só para investimentos não lança gasto: a base da reserva de paz pode ser digitada. Todo perfil que já
        // existe continua "auto" (calculado pelo fluxo de caixa), então nada muda para quem usa o fluxo.
        Schema::table('investor_profiles', function (Blueprint $table) {
            $table->string('essential_source', 10)->default('auto')->after('employment_type');
            $table->decimal('essential_monthly_manual', 15, 2)->nullable()->after('essential_source');
            $table->timestamp('essential_manual_updated_at')->nullable()->after('essential_monthly_manual');
        });
    }

    public function down(): void
    {
        Schema::table('investor_profiles', function (Blueprint $table) {
            $table->dropColumn(['essential_source', 'essential_monthly_manual', 'essential_manual_updated_at']);
        });
    }
};