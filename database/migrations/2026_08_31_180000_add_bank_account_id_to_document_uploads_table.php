<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('document_uploads', function (Blueprint $table) {
            // Qual conta o extrato pertence — sem isso, confirmar a
            // importação não tem como debitar/creditar o saldo certo.
            $table->foreignUuid('bank_account_id')->nullable()->after('member_id')
                ->constrained('bank_accounts')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('document_uploads', function (Blueprint $table) {
            $table->dropConstrainedForeignId('bank_account_id');
        });
    }
};
