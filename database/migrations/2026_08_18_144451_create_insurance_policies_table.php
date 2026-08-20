<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('insurance_policies', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('profile_id')->constrained('financial_profiles')->cascadeOnDelete();

            // Nulo = seguro familiar (residência, por exemplo).
            $table->foreignUuid('member_id')->nullable()->constrained('profile_members')->nullOnDelete();

            $table->string('insurance_type', 20);
            $table->string('insurer_name', 255);
            $table->string('policy_number', 100)->nullable();
            $table->decimal('coverage_amount', 15, 2)->nullable();
            $table->decimal('monthly_premium', 15, 2);
            $table->decimal('annual_premium', 15, 2)->nullable();
            $table->string('payment_frequency', 20);

            $table->foreignUuid('bank_account_id')->nullable()->constrained('bank_accounts')->nullOnDelete();
            $table->date('start_date');
            $table->date('expiry_date')->nullable();
            $table->boolean('is_active')->default(true);

            // Lista de beneficiários com percentuais.
            //
            // A produção roda MariaDB, onde `json` é apelido de LONGTEXT com
            // uma restrição — não o tipo nativo do MySQL 8. O cast `array`
            // do Eloquent funciona nos dois, mas NÃO use funções JSON do
            // banco em queries aqui: elas divergem entre os dois motores.
            $table->json('beneficiaries')->nullable();

            $table->text('notes')->nullable();
            $table->uuid('source_document_id')->nullable();
            $table->foreignUuid('created_by_user_id')->constrained('users');
            $table->timestamps();

            $table->index(['profile_id', 'is_active']);
            $table->index(['profile_id', 'insurance_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('insurance_policies');
    }
};
