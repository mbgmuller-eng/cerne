<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('credit_cards', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('profile_id')->constrained('financial_profiles')->cascadeOnDelete();
            $table->foreignUuid('member_id')->constrained('profile_members')->cascadeOnDelete();

            $table->string('card_name', 100);
            $table->string('bank_name', 100);
            $table->string('card_brand', 20);
            $table->decimal('credit_limit', 15, 2)->default(0);

            // 1–31. Dia 31 em mês curto cai no último dia — ver CreditCard::closingDateFor().
            $table->unsignedTinyInteger('closing_day');
            $table->unsignedTinyInteger('due_day');

            $table->char('last_four_digits', 4)->nullable();

            $table->boolean('is_joint')->default(false);
            $table->boolean('visible_to_partner')->default(true);
            $table->boolean('included_in_consolidated')->default(true);

            $table->char('color_hex', 7)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['profile_id', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('credit_cards');
    }
};
