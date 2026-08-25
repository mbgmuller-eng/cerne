<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('insurance_policies', function (Blueprint $table) {
            // Cobertura por item ("Morte qualquer causa: R$ X", "Doenças
            // graves: R$ Y"...), não só o total em coverage_amount. Mesmo
            // padrão de beneficiaries: JSON em vez de tabela nova — a
            // produção roda MariaDB, onde json é LONGTEXT com restrição
            // (ver comentário na migration de insurance_policies).
            $table->json('coverages')->nullable()->after('coverage_amount');
        });
    }

    public function down(): void
    {
        Schema::table('insurance_policies', function (Blueprint $table) {
            $table->dropColumn('coverages');
        });
    }
};
