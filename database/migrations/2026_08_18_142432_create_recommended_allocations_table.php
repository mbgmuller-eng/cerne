<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('recommended_allocations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('profile_id')->constrained('financial_profiles')->cascadeOnDelete();
            $table->foreignUuid('investor_profile_id')->constrained('investor_profiles')->cascadeOnDelete();

            $table->string('asset_class', 30);
            $table->decimal('target_percentage', 5, 2);
            $table->timestamps();

            $table->unique(['investor_profile_id', 'asset_class']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('recommended_allocations');
    }
};
